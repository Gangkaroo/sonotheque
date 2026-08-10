# Sonotheque Audio Intelligence

Audio Intelligence analyzes the sound of local tracks and uses those results
for musical similarity features. It is optional, local, and disabled by
default. Playback, library scans, and normal browsing do not depend on it.

## What It Does

For each track, Sonotheque analyzes up to three representative 30-second
windows. The local analyzer extracts characteristics such as tempo, key,
danceability, dynamic complexity, loudness, and a 1,280-dimensional musical
embedding. The embedding is a compact numeric description used to locate
tracks with similar audio characteristics.

Sonotheque stores the results in PostgreSQL and uses pgvector to search nearby
embeddings efficiently. It does not precompute or store every possible pair of
tracks.

Audio Intelligence currently provides:

- **Similar tracks** from track details.
- **Continue this mood** from the current queue.
- A reviewable similar-track queue before playback changes.
- **Order by similarity** for playlists, with a chosen opening track, a preview,
  and an undo snapshot.
- Optional refinement using tempo, key, and intensity.
- Optional local personalization based only on explicit relevant and
  not-relevant ratings.

It is not a language model, and Sonotheque does not train an audio model from
scratch. The base embedding model remains unchanged when you rate matches.

## Privacy And Resource Use

Analysis is performed locally. Music files and embeddings are not sent to an
online provider. Analyzer containers have networking disabled and receive the
model and music folders through read-only mounts.

The feature can use substantial CPU time, GPU time, energy, and disk space for
the model image and stored results. Nothing is analyzed merely because the
settings page is opened or the workspace is enabled. The analyzer starts only
for an explicit health check, benchmark, or analysis run.

Disabling Audio Intelligence stops active work but retains completed results.
Those results become usable again if the feature is re-enabled.

## Requirements

Before using the feature, Sonotheque needs:

1. A scanned music library.
2. PostgreSQL with the pgvector extension. Current Sonotheque Compose databases
   already provide it.
3. Docker Desktop for the supported analyzer setup.
4. The Sonotheque CPU analyzer image, and optionally its CUDA image.
5. A separately obtained and reviewed Discogs multi-similarity EffNet model
   file.
6. The dedicated `analysis` queue worker, which the normal Sonotheque start
   scripts already run.

The model and analyzer have their own licenses. Review them for the intended
use before enabling analysis. Sonotheque never downloads the model
automatically.

The current analyzer workflow is supported by the development runtime. The
portable packaged release starts the analysis queue but does not yet provision
the optional analyzer image, model, or Docker access from its backend
container. Packaged Audio Intelligence delivery remains a separate roadmap
item.

## Provision The Analyzer

These steps are for a development installation on Windows.

Build the CPU image from the repository root:

```powershell
docker build --tag sonotheque-audio-intelligence:analysis .\audio-intelligence
```

For a compatible NVIDIA GPU exposed to Docker Desktop, optionally build the
CUDA image:

```powershell
docker build --file .\audio-intelligence\Dockerfile.cuda `
  --tag sonotheque-audio-intelligence:cuda .\audio-intelligence
```

Place the reviewed model file in a stable local location. The ignored
`audio-intelligence/models` directory is suitable for development. Configure
`backend/.env`:

```dotenv
AUDIO_INTELLIGENCE_DRIVER=essentia_docker
AUDIO_INTELLIGENCE_MODEL_PATH=C:/absolute/path/to/model.pb
AUDIO_INTELLIGENCE_DOCKER_IMAGE=sonotheque-audio-intelligence:analysis
AUDIO_INTELLIGENCE_BENCHMARK_CPU_IMAGE=sonotheque-audio-intelligence:analysis
AUDIO_INTELLIGENCE_BENCHMARK_CUDA_IMAGE=sonotheque-audio-intelligence:cuda
AUDIO_INTELLIGENCE_ACCELERATOR=cpu
AUDIO_INTELLIGENCE_PERSISTENT=false
AUDIO_INTELLIGENCE_CPU_LIMIT=2
AUDIO_INTELLIGENCE_MEMORY_LIMIT=4g
AUDIO_INTELLIGENCE_PREPARATION_WORKERS=2
```

Restart Sonotheque after changing the environment. Start with CPU unless CUDA
has already been verified on this machine. The in-app benchmark can compare
both methods later without changing analysis results or progress.

## First Analysis

Open **Settings > Audio Intelligence** and follow this sequence:

1. Enable **Audio Intelligence**. This only enables the workspace.
2. Under **Collection analysis**, select one library root or all roots. A single
   root is often easier to monitor for the first long run.
3. Select **Prepare collection**. Preparation enumerates eligible tracks,
   calculates missing tag-independent audio fingerprints, and links reusable
   results. It does not run model inference.
4. When preparation is complete, select **Run collection analysis**.
5. Leave the analysis worker running. The progress and remaining-time estimate
   update in the background.
6. Pause when the machine is needed for other demanding work. Resume continues
   the same run.
7. Repeat for other library roots if they were not included in the first run.

An Advanced validation sample is no longer required before collection
analysis. It remains available as an optional diagnostic for checking a small,
representative set before committing to a long run.

## Resume, Reuse, And Changes To Files

Preparation and analysis are durable:

- Completed tracks are not analyzed again when a run is paused, cancelled, or
  restarted.
- Overlapping all-root and root-specific runs reuse existing results.
- Tag edits, renames, and moves reuse results because analysis is keyed by a
  tag-independent audio-content fingerprint.
- A changed audio stream or fingerprint version creates new work.
- Duplicate files with the same audio content can reuse one analysis artifact.
- A forcibly interrupted analyzer may repeat only its last uncommitted chunk.

Changing from CPU to CUDA does not invalidate results. A model change creates a
new versioned analysis profile. Sonotheque keeps the better-covered previous
profile active for similarity features until the new profile reaches at least
the same coverage.

## CPU, CUDA, And Benchmarking

CPU works without CUDA and is the default. CUDA requires a compatible NVIDIA
GPU, a functioning Docker GPU runtime, and the separately built CUDA image.
Sonotheque never silently falls back from the selected method.

Under **Advanced diagnostics**, run the CPU/CUDA benchmark after provisioning
both images. It uses a small fixed set of tracks, verifies that CPU and CUDA
produce equivalent results, records throughput, and recommends the faster
available method. The benchmark does not write analysis artifacts or advance a
collection run.

Apply the preferred method in the normal Audio Intelligence settings. The
selection affects future chunks only. If persistent mode is enabled in the
environment, the model remains loaded between chunks during a run and is
released when the run stops, pauses, fails, or completes.

## Using Similarity Features

### Similar Tracks

Open a track detail page and select **Similar tracks**. Review the proposed
matches before replacing or extending playback. Tracks not analyzed by the
currently active profile cannot participate.

### Continue This Mood

Open the queue and use **Continue this mood** beside the current track. The
current track remains in place and Sonotheque replaces only the unplayed queue
tail after confirmation.

### Playlist Ordering

Open a playlist and choose **Order by similarity**. Select the opening track,
create a preview, then either apply it to the playlist or save it as a new
playlist. Unanalyzed tracks remain in their existing relative order at the end.
Applied changes keep an undo snapshot.

## Refinement And Personalization

**Recommendation refinement** optionally adjusts the embedding order using
tempo, key, and intensity. The sliders define maximum penalties, not new model
weights. Missing characteristics are ignored, and half or double tempo can
still be treated as compatible.

**Local personalization** uses only ratings made in the Advanced similarity
review. Training requires at least 20 ratings, including at least five relevant
and five not relevant. It learns small, bounded adjustments to the visible
refinement influences. Listening history, favorites, and playlists are not
used. The profile can be disabled, retrained, or reset without touching audio
analysis artifacts.

## Advanced Diagnostics

Normal listening does not require this section. It contains:

- An explicit analyzer health check and active model identity.
- The CPU/CUDA benchmark.
- An optional 50-to-500-track validation sample.
- A bounded analyzed-pool expansion tool used for similarity review.
- Feature distributions and a structured nearest-neighbour review.
- Controls to train or reset local personalization.

Validation and pool expansion are retained because they provide reproducible
quality diagnostics. They are not older alternatives to collection analysis.

## Troubleshooting

### Analyzer Not Configured

Confirm that `AUDIO_INTELLIGENCE_DRIVER=essentia_docker`, the model path exists,
and the configured image has been built. Restart the backend and analysis queue
worker after environment changes.

### CUDA Unavailable

Use CPU, or confirm that Docker Desktop can expose the NVIDIA GPU to Linux
containers. Build the CUDA image and rerun the benchmark. Sonotheque will not
switch methods automatically.

### Analysis Does Not Progress

Check **Settings > System** for the Audio analysis worker. A healthy worker is
either ready or busy. Restart Sonotheque if its heartbeat is missing. A stale
run becomes resumable after the configured safety interval, ten minutes by
default.

### Disk Full

Docker images and build cache can consume space on Docker Desktop's virtual
disk even when music drives have free space. Inspect Docker Desktop storage and
remove unused build cache or obsolete images before retrying. Do not delete the
PostgreSQL volume.

### Similar Tracks Are Missing

Make sure Audio Intelligence is enabled and that both the source and enough
candidate tracks have completed analysis under the active model profile. Run
or resume collection analysis for the relevant library roots.

### Playback Problems

Audio analysis is isolated from streaming. Pausing, failure, or unavailability
of the analyzer should not stop playback. Treat a playback failure as a
separate streaming or browser issue.
