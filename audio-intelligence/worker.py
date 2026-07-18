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
import platform
import sys
import time
from pathlib import Path
from typing import Any

PROTOCOL_VERSION = 1
EMBEDDING_DIMENSIONS = 1280
MODEL_NAME = "Discogs multi-similarity EffNet"
MODEL_VERSION = "1"
MODEL_LICENSE = "CC BY-NC-SA 4.0"
ANALYZER_LICENSE = "AGPL-3.0"
WINDOW_SECONDS = 30.0


def emit(payload: dict[str, Any]) -> None:
    print(json.dumps(payload, ensure_ascii=False, allow_nan=False))


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
    except OSError as exception:
        emit({"status": "error", "message": str(exception), "profile": None})
        return 0

    emit({
        "status": "ready",
        "message": "The local Essentia pilot analyzer is ready.",
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


def analyze_item(
    item: dict[str, Any],
    numpy: Any,
    standard: Any,
    embedding_model: Any,
) -> dict[str, Any]:
    started = time.perf_counter()
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
    embeddings: list[Any] = []
    decode_seconds = 0.0
    feature_seconds = 0.0
    embedding_seconds = 0.0

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
        embedding_audio = resample_for_embedding(feature_audio)
        window_embeddings = numpy.asarray(embedding_model(embedding_audio), dtype=numpy.float32)
        if window_embeddings.ndim == 1:
            window_embedding = window_embeddings
        else:
            window_embedding = window_embeddings.mean(axis=0)
        embeddings.append(window_embedding)
        embedding_seconds += time.perf_counter() - embedding_started

    if not embeddings:
        raise ValueError("No decodable audio was found in the representative windows.")

    combined = numpy.stack(embeddings).mean(axis=0)
    if combined.shape != (EMBEDDING_DIMENSIONS,):
        raise ValueError(f"The model returned embedding shape {combined.shape}.")
    norm = float(numpy.linalg.norm(combined))
    if norm > 0:
        combined = combined / norm

    strongest_key = max(keys, key=lambda candidate: candidate[2]) if keys else None
    return {
        "itemId": item_id,
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
        "runtimeMs": round((time.perf_counter() - started) * 1000),
        "windowsAnalyzed": len(embeddings),
        "timings": {
            "decodeMs": round(decode_seconds * 1000),
            "featureExtractionMs": round(feature_seconds * 1000),
            "embeddingMs": round(embedding_seconds * 1000),
        },
        "hardware": {
            "system": platform.system(),
            "machine": platform.machine(),
            "processor": platform.processor(),
            "python": platform.python_version(),
        },
        "error": None,
    }


def analyze_batch(model_path: Path | None) -> int:
    if model_path is None or not model_path.is_file():
        print("The configured model file does not exist.", file=sys.stderr)
        return 2

    try:
        numpy, standard, _essentia_version = load_dependencies()
        embedding_model = standard.TensorflowPredictEffnetDiscogs(
            graphFilename=str(model_path),
            output="PartitionedCall:1",
        )
        request = json.load(sys.stdin)
    except Exception as exception:
        print(str(exception), file=sys.stderr)
        return 2

    if request.get("protocolVersion") != PROTOCOL_VERSION or not isinstance(request.get("items"), list):
        print("The analysis request uses an incompatible protocol.", file=sys.stderr)
        return 2

    results = []
    for item in request["items"]:
        try:
            results.append(analyze_item(item, numpy, standard, embedding_model))
        except Exception as exception:
            item_id = item.get("itemId") if isinstance(item, dict) else None
            results.append({
                "itemId": item_id,
                "status": "failed",
                "features": {},
                "embedding": [],
                "runtimeMs": None,
                "windowsAnalyzed": None,
                "timings": {},
                "hardware": {},
                "error": str(exception)[:4000],
            })

    emit({"protocolVersion": PROTOCOL_VERSION, "results": results})
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=("health", "analyze-batch"))
    parser.add_argument("--model", type=Path)
    arguments = parser.parse_args()

    if arguments.operation == "health":
        return health(arguments.model)

    return analyze_batch(arguments.model)


if __name__ == "__main__":
    raise SystemExit(main())
