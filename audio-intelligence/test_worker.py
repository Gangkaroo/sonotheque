from io import BytesIO
from pathlib import Path
from types import SimpleNamespace
from unittest import TestCase
from unittest.mock import Mock, patch

import worker


class WorkerTest(TestCase):
    def test_cuda_embedding_batches_patches_across_audio_windows(self) -> None:
        class FakeNumpy:
            float32 = object()

            @staticmethod
            def asarray(values, dtype=None):
                return list(values)

            @staticmethod
            def concatenate(groups, axis=0):
                return [
                    value
                    for group in groups
                    for value in group
                ]

            @staticmethod
            def empty(shape, dtype=None):
                return []

            @staticmethod
            def pad(values, padding):
                return list(values) + [None] * padding[0][1]

        model = object.__new__(worker.TensorflowCudaEmbeddingModel)
        model.numpy = FakeNumpy()
        model.model_input = "input"
        model.model_output = "output"
        model._patches = Mock(side_effect=[
            list(range(40)),
            list(range(40, 80)),
            list(range(80, 90)),
        ])
        model.session = Mock()
        model.session.run.side_effect = lambda _output, feed_dict: [
            [value]
            for value in feed_dict["input"]
        ]

        outputs, preprocessing_seconds, inference_seconds = model.embed_many([
            "first",
            "second",
            "third",
        ])

        self.assertEqual([40, 40, 10], [len(output) for output in outputs])
        self.assertEqual([[0], [39]], [outputs[0][0], outputs[0][-1]])
        self.assertEqual([[80], [89]], [outputs[2][0], outputs[2][-1]])
        self.assertEqual(3, len(preprocessing_seconds))
        self.assertGreaterEqual(inference_seconds, 0)
        self.assertEqual(2, model.session.run.call_count)

    def test_framed_message_round_trip(self) -> None:
        stream = BytesIO()
        payload = {"operation": "health", "unicode": "Touche Amore"}

        worker.write_message(stream, payload)
        stream.seek(0)

        self.assertEqual(payload, worker.read_message(stream))

    def test_accelerator_uses_explicit_configuration(self) -> None:
        with patch.dict("os.environ", {"SONOTHEQUE_AUDIO_ACCELERATOR": "cuda"}):
            self.assertEqual("cuda", worker.accelerator())
        with patch.dict("os.environ", {"SONOTHEQUE_AUDIO_ACCELERATOR": "cpu"}):
            self.assertEqual("cpu", worker.accelerator())

    def test_preparation_workers_are_bounded(self) -> None:
        for configured, expected in [
            ("0", 1),
            ("3", 3),
            ("12", 4),
            ("invalid", 2),
        ]:
            with self.subTest(configured=configured):
                with patch.dict(
                    "os.environ",
                    {"SONOTHEQUE_AUDIO_PREPARATION_WORKERS": configured},
                ):
                    self.assertEqual(expected, worker.preparation_workers())

    def test_cpu_model_keeps_the_sequential_analysis_path(self) -> None:
        items = [{"itemId": 1}, {"itemId": 2}]
        completed = [
            {"itemId": 1, "status": "completed"},
            {"itemId": 2, "status": "completed"},
        ]

        with patch.object(worker, "analyze_item", side_effect=completed) as analyze:
            with patch.object(worker, "analyze_cuda_items") as analyze_cuda:
                response = worker.analyze_request(
                    {"protocolVersion": 1, "items": items},
                    Mock(),
                    Mock(),
                    Mock(),
                )

        self.assertEqual(completed, response["results"])
        self.assertEqual([items[0], items[1]], [
            call.args[0]
            for call in analyze.call_args_list
        ])
        analyze_cuda.assert_not_called()

    def test_cuda_pipeline_preserves_input_order(self) -> None:
        items = [{"itemId": item_id} for item_id in range(1, 6)]

        def prepare(item, _standard):
            return {
                "itemId": item["itemId"],
                "embeddingAudioWindows": [item["itemId"]],
            }

        def process(prepared_items, results, _numpy, _model):
            for index, item, _prepared in prepared_items:
                results[index] = {
                    "itemId": item["itemId"],
                    "status": "completed",
                }

        model = object.__new__(worker.TensorflowCudaEmbeddingModel)
        with patch.object(worker, "preparation_workers", return_value=2):
            with patch.object(worker, "prepare_item", side_effect=prepare):
                with patch.object(worker, "process_cuda_group", side_effect=process) as process_group:
                    results = worker.analyze_cuda_items(items, Mock(), Mock(), model)

        self.assertEqual([1, 2, 3, 4, 5], [
            result["itemId"]
            for result in results
        ])
        self.assertGreaterEqual(process_group.call_count, 2)

    def test_representative_windows_cover_short_and_long_tracks(self) -> None:
        self.assertEqual([(0.0, 30.0)], worker.representative_windows(None))
        self.assertEqual([(0.0, 40.0)], worker.representative_windows(40.0))
        self.assertEqual(
            [(21.0, 51.0), (105.0, 135.0), (189.0, 219.0)],
            worker.representative_windows(240.0),
        )

    def test_audio_window_uses_bounded_neutral_gain_loader(self) -> None:
        source = Path("/music/track.mp3")
        audio = [0.1, -0.1]
        configured_loader = Mock(return_value=audio)
        easy_loader = Mock(return_value=configured_loader)
        standard = SimpleNamespace(EasyLoader=easy_loader)

        result = worker.load_audio_window(
            source,
            15.0,
            45.0,
            standard,
        )

        self.assertIs(audio, result)
        easy_loader.assert_called_once_with(
            filename=str(source),
            sampleRate=44100,
            replayGain=-6,
            startTime=15.0,
            endTime=45.0,
        )
        configured_loader.assert_called_once_with()
