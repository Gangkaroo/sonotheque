from pathlib import Path
from types import SimpleNamespace
from unittest import TestCase
from unittest.mock import Mock

import worker


class WorkerTest(TestCase):
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
