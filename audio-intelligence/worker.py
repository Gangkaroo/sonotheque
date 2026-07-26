#!/usr/bin/env python3
"""Optional Sonotheque audio-analysis worker.

This process never downloads dependencies or models. Provision Essentia and the
configured Discogs EffNet model separately before selecting the essentia_cli
driver.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import platform
import socket
import socketserver
import sys
import time
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from pathlib import Path
from typing import Any

PROTOCOL_VERSION = 1
EMBEDDING_DIMENSIONS = 1280
MODEL_NAME = "Discogs multi-similarity EffNet"
MODEL_VERSION = "1"
MODEL_LICENSE = "CC BY-NC-SA 4.0"
ANALYZER_LICENSE = "AGPL-3.0"
WINDOW_SECONDS = 30.0
FRAME_SIZE = 512
HOP_SIZE = 256
PATCH_SIZE = 128
PATCH_HOP_SIZE = 62
BATCH_SIZE = 64
MAX_MESSAGE_BYTES = 64 * 1024 * 1024


def accelerator() -> str:
    configured = os.environ.get("SONOTHEQUE_AUDIO_ACCELERATOR", "cpu").strip().lower()

    return "cuda" if configured == "cuda" else "cpu"


def preparation_workers() -> int:
    try:
        configured = int(os.environ.get("SONOTHEQUE_AUDIO_PREPARATION_WORKERS", "2"))
    except ValueError:
        configured = 2

    return max(1, min(4, configured))


def emit(payload: dict[str, Any]) -> None:
    print(json.dumps(payload, ensure_ascii=False, allow_nan=False))


def read_message(source: Any) -> dict[str, Any]:
    header = source.read(8)
    if len(header) != 8:
        raise ValueError("The analyzer request header is incomplete.")
    length = int.from_bytes(header, byteorder="big")
    if length < 1 or length > MAX_MESSAGE_BYTES:
        raise ValueError("The analyzer request size is invalid.")
    payload = source.read(length)
    if len(payload) != length:
        raise ValueError("The analyzer request body is incomplete.")
    value = json.loads(payload.decode("utf-8"))
    if not isinstance(value, dict):
        raise ValueError("The analyzer request must be an object.")

    return value


def write_message(target: Any, payload: dict[str, Any]) -> None:
    encoded = json.dumps(
        payload,
        ensure_ascii=False,
        allow_nan=False,
    ).encode("utf-8")
    if len(encoded) > MAX_MESSAGE_BYTES:
        raise ValueError("The analyzer response is too large.")
    target.write(len(encoded).to_bytes(8, byteorder="big"))
    target.write(encoded)
    target.flush()


def profile(model_path: Path, essentia_version: str) -> dict[str, Any]:
    return {
        "key": "essentia-discogs-effnet",
        "protocolVersion": PROTOCOL_VERSION,
        "analyzerName": "Essentia",
        "analyzerVersion": essentia_version,
        "analyzerLicense": ANALYZER_LICENSE,
        "modelName": MODEL_NAME,
        "modelVersion": MODEL_VERSION,
        "modelChecksum": file_checksum(model_path),
        "modelLicense": MODEL_LICENSE,
        "embeddingDimensions": EMBEDDING_DIMENSIONS,
        "sampleRate": 16000,
        "manifest": {
            "featureSampleRate": 44100,
            "windowSeconds": WINDOW_SECONDS,
            "windowStrategy": "full-short-or-three-representative-windows",
            "embeddingOutput": "PartitionedCall:1",
        },
    }


def file_checksum(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_dependencies() -> tuple[Any, Any, str]:
    import essentia  # type: ignore[import-not-found]
    import essentia.standard as standard  # type: ignore[import-not-found]
    import numpy  # type: ignore[import-not-found]

    return numpy, standard, str(essentia.__version__)


class TensorflowCudaEmbeddingModel:
    def __init__(self, model_path: Path, numpy: Any) -> None:
        import essentia  # type: ignore[import-not-found]
        import essentia.streaming as streaming  # type: ignore[import-not-found]
        import tensorflow  # type: ignore[import-not-found]

        if not tensorflow.config.list_physical_devices("GPU"):
            raise RuntimeError("The CUDA analyzer cannot access an NVIDIA GPU.")

        graph_definition = tensorflow.compat.v1.GraphDef()
        graph_definition.ParseFromString(model_path.read_bytes())
        graph = tensorflow.Graph()
        with graph.as_default():
            tensorflow.import_graph_def(graph_definition, name="")

        self.numpy = numpy
        self.essentia = essentia
        self.streaming = streaming
        self.session = tensorflow.compat.v1.Session(graph=graph)
        self.model_input = graph.get_tensor_by_name("serving_default_melspectrogram:0")
        self.model_output = graph.get_tensor_by_name("PartitionedCall:1")

    def _patches(self, audio: Any) -> Any:
        source = self.streaming.VectorInput(audio)
        frame_cutter = self.streaming.FrameCutter(
            frameSize=FRAME_SIZE,
            hopSize=HOP_SIZE,
        )
        input_features = self.streaming.TensorflowInputMusiCNN()
        pool = self.essentia.Pool()
        source.data >> frame_cutter.signal
        frame_cutter.frame >> input_features.frame
        input_features.bands >> (pool, "bands")
        self.essentia.run(source)
        bands = self.numpy.asarray(pool["bands"], dtype=self.numpy.float32)
        patches = self.numpy.asarray([
            bands[start:start + PATCH_SIZE]
            for start in range(0, len(bands) - PATCH_SIZE + 1, PATCH_HOP_SIZE)
        ], dtype=self.numpy.float32)

        return patches

    def embed_many(self, audio_windows: list[Any]) -> tuple[list[Any], list[float], float]:
        patch_groups = []
        preprocessing_seconds = []
        for audio in audio_windows:
            started = time.perf_counter()
            patches = self._patches(audio)
            preprocessing_seconds.append(time.perf_counter() - started)
            patch_groups.append(patches)

        non_empty = [patches for patches in patch_groups if len(patches) > 0]
        if not non_empty:
            return [
                self.numpy.empty((0, EMBEDDING_DIMENSIONS), dtype=self.numpy.float32)
                for _audio in audio_windows
            ], preprocessing_seconds, 0.0

        combined_patches = self.numpy.concatenate(non_empty, axis=0)
        combined_embeddings = []
        inference_started = time.perf_counter()
        for start in range(0, len(combined_patches), BATCH_SIZE):
            patches = combined_patches[start:start + BATCH_SIZE]
            patch_count = len(patches)
            batch = self.numpy.pad(
                patches,
                ((0, BATCH_SIZE - patch_count), (0, 0), (0, 0)),
            )
            embeddings = self.session.run(
                self.model_output,
                feed_dict={self.model_input: batch},
            )
            combined_embeddings.extend(embeddings[:patch_count])
        inference_seconds = time.perf_counter() - inference_started

        outputs = []
        offset = 0
        for patches in patch_groups:
            patch_count = len(patches)
            outputs.append(self.numpy.asarray(
                combined_embeddings[offset:offset + patch_count],
                dtype=self.numpy.float32,
            ))
            offset += patch_count

        return outputs, preprocessing_seconds, inference_seconds

    def __call__(self, audio: Any) -> Any:
        outputs, _preprocessing_seconds, _inference_seconds = self.embed_many([audio])

        return outputs[0]

    def close(self) -> None:
        self.session.close()


def create_embedding_model(model_path: Path, numpy: Any, standard: Any) -> Any:
    if accelerator() == "cuda":
        return TensorflowCudaEmbeddingModel(model_path, numpy)

    return standard.TensorflowPredictEffnetDiscogs(
        graphFilename=str(model_path),
        output="PartitionedCall:1",
    )


def health(model_path: Path | None) -> int:
    try:
        _numpy, _standard, essentia_version = load_dependencies()
    except (ImportError, OSError) as exception:
        emit({
            "status": "dependency_missing",
            "message": f"Essentia and NumPy are not available: {exception}",
            "profile": None,
        })
        return 0

    if model_path is None or not model_path.is_file():
        emit({
            "status": "model_missing",
            "message": "Configure a readable Discogs EffNet model file.",
            "profile": None,
        })
        return 0

    try:
        analyzer_profile = profile(model_path, essentia_version)
        analyzer = create_embedding_model(model_path, _numpy, _standard)
        if hasattr(analyzer, "close"):
            analyzer.close()
    except Exception as exception:
        emit({"status": "error", "message": str(exception), "profile": None})
        return 0

    emit({
        "status": "ready",
        "message": "The local Essentia audio analyzer is ready.",
        "profile": analyzer_profile,
    })
    return 0


def representative_windows(duration: float | None) -> list[tuple[float, float]]:
    if duration is None or duration <= 0:
        return [(0.0, WINDOW_SECONDS)]
    if duration <= 45:
        return [(0.0, duration)]

    maximum_start = max(0.0, duration - WINDOW_SECONDS)
    starts = [
        min(maximum_start, max(0.0, duration * position - WINDOW_SECONDS / 2))
        for position in (0.15, 0.5, 0.85)
    ]
    unique_starts = list(dict.fromkeys(round(start, 3) for start in starts))
    return [(start, min(duration, start + WINDOW_SECONDS)) for start in unique_starts]


def median(values: list[float], numpy: Any) -> float | None:
    return float(numpy.median(values)) if values else None


def load_audio_window(
    source: Path,
    start: float,
    end: float,
    standard: Any,
) -> Any:
    return standard.EasyLoader(
        filename=str(source),
        sampleRate=44100,
        replayGain=-6,
        startTime=start,
        endTime=end,
    )()


def prepare_item(
    item: dict[str, Any],
    standard: Any,
) -> dict[str, Any]:
    item_id = item.get("itemId")
    source = Path(str(item.get("path", "")))
    duration_value = item.get("durationSeconds")
    duration = float(duration_value) if isinstance(duration_value, (int, float)) else None

    if not isinstance(item_id, int) or not source.is_file():
        raise ValueError("The analysis item has no valid identity or readable file.")

    windows = representative_windows(duration)
    bpm_values: list[float] = []
    danceability_values: list[float] = []
    dynamic_values: list[float] = []
    loudness_values: list[float] = []
    keys: list[tuple[str, str, float]] = []
    embedding_audio_windows = []
    decode_seconds = 0.0
    feature_seconds = 0.0
    embedding_preparation_seconds = 0.0

    rhythm = standard.RhythmExtractor2013(method="multifeature")
    key_extractor = standard.KeyExtractor()
    danceability = standard.Danceability()
    dynamics = standard.DynamicComplexity()
    loudness = standard.Loudness()
    resample_for_embedding = standard.Resample(
        inputSampleRate=44100,
        outputSampleRate=16000,
    )

    for start, end in windows:
        decode_started = time.perf_counter()
        feature_audio = load_audio_window(source, start, end, standard)
        decode_seconds += time.perf_counter() - decode_started
        if len(feature_audio) == 0:
            continue

        feature_started = time.perf_counter()
        bpm, _beats, _confidence, _estimates, _intervals = rhythm(feature_audio)
        key, scale, strength = key_extractor(feature_audio)
        dance_score, _dfa = danceability(feature_audio)
        dynamic_complexity, _average_loudness = dynamics(feature_audio)

        bpm_values.append(float(bpm))
        danceability_values.append(float(dance_score))
        dynamic_values.append(float(dynamic_complexity))
        loudness_values.append(float(loudness(feature_audio)))
        keys.append((str(key), str(scale), float(strength)))
        feature_seconds += time.perf_counter() - feature_started

        embedding_started = time.perf_counter()
        embedding_audio_windows.append(resample_for_embedding(feature_audio))
        embedding_preparation_seconds += time.perf_counter() - embedding_started

    if not embedding_audio_windows:
        raise ValueError("No decodable audio was found in the representative windows.")

    return {
        "itemId": item_id,
        "bpmValues": bpm_values,
        "danceabilityValues": danceability_values,
        "dynamicValues": dynamic_values,
        "loudnessValues": loudness_values,
        "keys": keys,
        "embeddingAudioWindows": embedding_audio_windows,
        "decodeSeconds": decode_seconds,
        "featureSeconds": feature_seconds,
        "embeddingPreparationSeconds": embedding_preparation_seconds,
    }


def finalize_item(
    prepared: dict[str, Any],
    window_embeddings: list[Any],
    numpy: Any,
    model_embedding_seconds: float,
    runtime_seconds: float,
) -> dict[str, Any]:
    embeddings = []
    for window_embeddings_array in window_embeddings:
        values = numpy.asarray(window_embeddings_array, dtype=numpy.float32)
        if len(values) == 0:
            continue
        embeddings.append(values if values.ndim == 1 else values.mean(axis=0))

    if not embeddings:
        raise ValueError("No model embeddings were produced for the decoded audio.")

    combined = numpy.stack(embeddings).mean(axis=0)
    if combined.shape != (EMBEDDING_DIMENSIONS,):
        raise ValueError(f"The model returned embedding shape {combined.shape}.")
    norm = float(numpy.linalg.norm(combined))
    if norm > 0:
        combined = combined / norm

    bpm_values = prepared["bpmValues"]
    danceability_values = prepared["danceabilityValues"]
    dynamic_values = prepared["dynamicValues"]
    loudness_values = prepared["loudnessValues"]
    keys = prepared["keys"]
    strongest_key = max(keys, key=lambda candidate: candidate[2]) if keys else None
    embedding_seconds = prepared["embeddingPreparationSeconds"] + model_embedding_seconds
    return {
        "itemId": prepared["itemId"],
        "status": "completed",
        "features": {
            "bpm": median(bpm_values, numpy),
            "danceability": median(danceability_values, numpy),
            "dynamicComplexity": median(dynamic_values, numpy),
            "loudness": median(loudness_values, numpy),
            "key": strongest_key[0] if strongest_key else None,
            "scale": strongest_key[1] if strongest_key else None,
            "keyStrength": strongest_key[2] if strongest_key else None,
        },
        "embedding": [float(value) for value in combined.tolist()],
        "runtimeMs": round(runtime_seconds * 1000),
        "windowsAnalyzed": len(embeddings),
        "timings": {
            "decodeMs": round(prepared["decodeSeconds"] * 1000),
            "featureExtractionMs": round(prepared["featureSeconds"] * 1000),
            "embeddingMs": round(embedding_seconds * 1000),
        },
        "hardware": {
            "accelerator": accelerator(),
            "preparationWorkers": preparation_workers() if accelerator() == "cuda" else 1,
            "system": platform.system(),
            "machine": platform.machine(),
            "processor": platform.processor(),
            "python": platform.python_version(),
        },
        "error": None,
    }


def analyze_item(
    item: dict[str, Any],
    numpy: Any,
    standard: Any,
    embedding_model: Any,
) -> dict[str, Any]:
    started = time.perf_counter()
    prepared = prepare_item(item, standard)
    model_started = time.perf_counter()
    window_embeddings = [
        embedding_model(audio)
        for audio in prepared["embeddingAudioWindows"]
    ]
    model_embedding_seconds = time.perf_counter() - model_started

    return finalize_item(
        prepared,
        window_embeddings,
        numpy,
        model_embedding_seconds,
        time.perf_counter() - started,
    )


def failed_result(item: Any, exception: Exception) -> dict[str, Any]:
    item_id = item.get("itemId") if isinstance(item, dict) else None

    return {
        "itemId": item_id,
        "status": "failed",
        "features": {},
        "embedding": [],
        "runtimeMs": None,
        "windowsAnalyzed": None,
        "timings": {},
        "hardware": {},
        "error": str(exception)[:4000],
}


def process_cuda_group(
    prepared_items: list[tuple[int, Any, dict[str, Any]]],
    results: list[dict[str, Any] | None],
    numpy: Any,
    embedding_model: TensorflowCudaEmbeddingModel,
) -> None:
    audio_windows = []
    boundaries = []
    for index, item, prepared in prepared_items:
        start = len(audio_windows)
        audio_windows.extend(prepared["embeddingAudioWindows"])
        boundaries.append((index, item, prepared, start, len(audio_windows)))

    try:
        embeddings, preprocessing_seconds, inference_seconds = embedding_model.embed_many(
            audio_windows,
        )
        patch_counts = [len(values) for values in embeddings]
        total_patches = sum(patch_counts)

        for index, item, prepared, start, end in boundaries:
            try:
                item_patch_count = sum(patch_counts[start:end])
                inference_share = (
                    inference_seconds * item_patch_count / total_patches
                    if total_patches > 0
                    else 0.0
                )
                model_seconds = sum(preprocessing_seconds[start:end]) + inference_share
                runtime_seconds = (
                    prepared["decodeSeconds"]
                    + prepared["featureSeconds"]
                    + prepared["embeddingPreparationSeconds"]
                    + model_seconds
                )
                results[index] = finalize_item(
                    prepared,
                    embeddings[start:end],
                    numpy,
                    model_seconds,
                    runtime_seconds,
                )
            except Exception as exception:
                results[index] = failed_result(item, exception)
    except Exception as exception:
        for index, item, _prepared in prepared_items:
            results[index] = failed_result(item, exception)


def analyze_cuda_items(
    items: list[Any],
    numpy: Any,
    standard: Any,
    embedding_model: TensorflowCudaEmbeddingModel,
) -> list[dict[str, Any]]:
    results: list[dict[str, Any] | None] = [None] * len(items)
    worker_count = min(preparation_workers(), max(1, len(items)))

    if worker_count == 1:
        prepared_items = []
        for index, item in enumerate(items):
            try:
                if not isinstance(item, dict):
                    raise ValueError("The analysis item must be an object.")
                prepared_items.append((index, item, prepare_item(item, standard)))
            except Exception as exception:
                results[index] = failed_result(item, exception)
        if prepared_items:
            process_cuda_group(prepared_items, results, numpy, embedding_model)
    else:
        item_iterator = iter(enumerate(items))
        pending = {}

        with ThreadPoolExecutor(max_workers=worker_count) as executor:
            def submit_next() -> None:
                try:
                    index, item = next(item_iterator)
                except StopIteration:
                    return
                pending[executor.submit(prepare_item, item, standard)] = (index, item)

            for _worker in range(worker_count):
                submit_next()

            prepared_group = []
            while pending:
                completed, _pending = wait(pending, return_when=FIRST_COMPLETED)
                for future in completed:
                    index, item = pending.pop(future)
                    submit_next()
                    try:
                        if not isinstance(item, dict):
                            raise ValueError("The analysis item must be an object.")
                        prepared_group.append((index, item, future.result()))
                    except Exception as exception:
                        results[index] = failed_result(item, exception)

                if prepared_group and (
                    len(prepared_group) >= worker_count or not pending
                ):
                    process_cuda_group(prepared_group, results, numpy, embedding_model)
                    prepared_group = []

    return [
        result if result is not None else failed_result(items[index], RuntimeError(
            "The analyzer did not produce a result.",
        ))
        for index, result in enumerate(results)
    ]


def analyze_request(
    request: dict[str, Any],
    numpy: Any,
    standard: Any,
    embedding_model: Any,
) -> dict[str, Any]:
    if request.get("protocolVersion") != PROTOCOL_VERSION or not isinstance(request.get("items"), list):
        raise ValueError("The analysis request uses an incompatible protocol.")

    items = request["items"]
    if isinstance(embedding_model, TensorflowCudaEmbeddingModel):
        results = analyze_cuda_items(items, numpy, standard, embedding_model)
    else:
        results = []
        for item in items:
            try:
                if not isinstance(item, dict):
                    raise ValueError("The analysis item must be an object.")
                results.append(analyze_item(item, numpy, standard, embedding_model))
            except Exception as exception:
                results.append(failed_result(item, exception))

    return {"protocolVersion": PROTOCOL_VERSION, "results": results}


def analyze_batch(model_path: Path | None) -> int:
    if model_path is None or not model_path.is_file():
        print("The configured model file does not exist.", file=sys.stderr)
        return 2

    embedding_model = None
    try:
        numpy, standard, _essentia_version = load_dependencies()
        embedding_model = create_embedding_model(model_path, numpy, standard)
        request = json.load(sys.stdin)
        if not isinstance(request, dict):
            raise ValueError("The analysis request must be an object.")
        emit(analyze_request(request, numpy, standard, embedding_model))
    except Exception as exception:
        print(str(exception), file=sys.stderr)
        return 2
    finally:
        if embedding_model is not None and hasattr(embedding_model, "close"):
            embedding_model.close()

    return 0


def serve(model_path: Path | None, socket_path: Path) -> int:
    if model_path is None or not model_path.is_file():
        print("The configured model file does not exist.", file=sys.stderr)
        return 2

    embedding_model = None
    try:
        numpy, standard, essentia_version = load_dependencies()
        embedding_model = create_embedding_model(model_path, numpy, standard)
        analyzer_profile = profile(model_path, essentia_version)
    except Exception as exception:
        print(str(exception), file=sys.stderr)
        return 2

    class RequestHandler(socketserver.StreamRequestHandler):
        def handle(self) -> None:
            try:
                request = read_message(self.rfile)
                operation = request.get("operation")
                if operation == "health":
                    response = {
                        "status": "ready",
                        "message": "The persistent Essentia audio analyzer is ready.",
                        "profile": analyzer_profile,
                    }
                elif operation == "analyze":
                    payload = request.get("request")
                    if not isinstance(payload, dict):
                        raise ValueError("The analysis payload must be an object.")
                    response = analyze_request(
                        payload,
                        numpy,
                        standard,
                        embedding_model,
                    )
                else:
                    raise ValueError("The analyzer operation is invalid.")
            except Exception as exception:
                response = {
                    "status": "error",
                    "message": str(exception)[:4000],
                }

            write_message(self.wfile, response)

    try:
        socket_path.unlink(missing_ok=True)
        socket_path.parent.mkdir(parents=True, exist_ok=True)
        with socketserver.UnixStreamServer(str(socket_path), RequestHandler) as server:
            os.chmod(socket_path, 0o600)
            server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        socket_path.unlink(missing_ok=True)
        if embedding_model is not None and hasattr(embedding_model, "close"):
            embedding_model.close()

    return 0


def client(socket_path: Path) -> int:
    try:
        request = json.load(sys.stdin)
        if not isinstance(request, dict):
            raise ValueError("The analyzer request must be an object.")
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
            connection.connect(str(socket_path))
            stream = connection.makefile("rwb")
            write_message(stream, request)
            response = read_message(stream)
        emit(response)
    except Exception as exception:
        print(str(exception), file=sys.stderr)
        return 2

    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "operation",
        choices=("health", "analyze-batch", "serve", "client"),
    )
    parser.add_argument("--model", type=Path)
    parser.add_argument(
        "--socket",
        type=Path,
        default=Path("/tmp/sonotheque-audio-analyzer.sock"),
    )
    arguments = parser.parse_args()

    if arguments.operation == "health":
        return health(arguments.model)
    if arguments.operation == "serve":
        return serve(arguments.model, arguments.socket)
    if arguments.operation == "client":
        return client(arguments.socket)

    return analyze_batch(arguments.model)


if __name__ == "__main__":
    raise SystemExit(main())
