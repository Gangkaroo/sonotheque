# Sonotheque Collection Assistant

The Collection Assistant is an optional, local language-model feature for
asking questions about a Sonotheque collection. It is independent from Audio
Intelligence, disabled by default, and must not affect normal browsing,
scanning, or playback.

## Current Feature

The implemented backend provides:

- an Ollama provider boundary that can be replaced by another local provider;
- persisted opt-in and model selection;
- explicit discovery of models already installed in Ollama;
- an explicit model check that requires a structured tool call; and
- a protected Settings tab showing the server-configured Ollama address;
- read-only collection-summary, catalog-search, listening-statistics, and
  analyzed-audio similarity tools;
- fixed library-root scope and bounded result sizes; and
- a protected query endpoint with a maximum of four model rounds and six tool
  calls per question;
- a dedicated, library-root-aware Assistant view;
- bounded conversational context and locally retained conversations separated
  by library-root scope; and
- verified Sonotheque references rendered as navigation actions instead of
  trusting model-generated URLs; and
- bounded similarity playback previews that require an explicit browser-side
  confirmation before replacing or extending the queue.

Opening Settings does not contact Ollama, load a model, or start a process.
Sonotheque never downloads a language model or starts Ollama automatically.
Discovery and model verification run only when their buttons are selected.

Open **Assistant** from the main navigation after setup. The active library-root
selector also scopes assistant questions. A separate local conversation is kept
for **All library roots** and for each selected root. Only the latest eight
messages are sent back to the model as conversational context, while up to forty
messages per scope remain visible on that browser. Clearing a conversation only
removes that browser's local copy.

## Local Setup

Install Ollama separately and install a model that supports tool calling. The
recommended starting model is `qwen3:4b`, which supports tool calls while using
substantially less memory than the 8B variant. `qwen3:8b` remains a useful
quality-oriented option on machines with enough free graphics memory. Other
models remain selectable because hardware and model quality differ between
installations.

For a development backend running directly on Windows, the default endpoint is:

```dotenv
COLLECTION_ASSISTANT_OLLAMA_URL=http://127.0.0.1:11434
```

For the packaged backend running in Docker, use:

```dotenv
COLLECTION_ASSISTANT_OLLAMA_URL=http://host.docker.internal:11434
```

Sonotheque keeps a model used by the assistant loaded for 15 minutes by
default, uses a 4,096-token context window, and limits concise answers to 256
generated tokens. These values can be adjusted for the host machine:

```dotenv
COLLECTION_ASSISTANT_KEEP_ALIVE=15m
COLLECTION_ASSISTANT_CONTEXT_WINDOW=4096
COLLECTION_ASSISTANT_MAX_ANSWER_TOKENS=256
```

A longer keep-alive makes follow-up questions faster but keeps the model in
RAM or graphics memory for longer. Reduce it when those resources need to be
freed quickly. Sonotheque sends the tool catalog only while selecting the
required catalog lookups; the final answer round receives just the verified
results.

Unambiguous collection-total questions, such as “How many albums and tracks
are in this collection?”, use the same guarded metrics tool directly and do not
start the language model. Qualified questions still use the model, so a request
such as “How many albums by The Cure do I have?” is not mistaken for a global
collection count.

The address is intentionally configured on the server rather than accepted as
an arbitrary browser-supplied URL. This keeps the settings API from becoming a
general server-side request mechanism.

After starting Ollama, open **Settings > Assistant**:

1. Select **Find installed models**.
2. Select a model or enter its exact installed name.
3. Select **Test model**. The check asks the model to call a harmless restricted
   Sonotheque test tool; a plain-text response does not pass. A successful test
   also leaves the model warm for the configured keep-alive period.
4. Enable the assistant and save the settings after the test succeeds.
5. Open **Assistant** in the main navigation and ask a question. Catalog results
   returned by the guarded tools appear as links below the answer.

To ask for similar music, Audio Intelligence must also be enabled and the
reference track must already have a compatible analysis result. Name the track
and, where useful, its artist, for example: “Find five tracks similar to
Pictures of You by The Cure.” The search stays inside the active library-root
scope and excludes the same album and artist by default. If several tracks have
the same title, the assistant returns the matching references instead of
guessing; add the artist name to disambiguate them.

Similarity answers include the analyzed coverage of the selected scope and the
ranking method used. Raw embedding similarity and an optional refined score may
both be present. These values order candidates; they are not probabilities or
claims that two tracks are objectively alike. When a question explicitly asks
to play the results or add them to the queue, Sonotheque displays the verified
tracks in a preview. The browser changes playback only after **Play now** or
**Add to queue** is selected; cancelling the preview has no playback effect.

## Safety Boundary

The model can call only named, read-only tools with strict input schemas,
result limits, library-root scope, timeouts, and a bounded tool loop. The first
tool set provides selected collection counts, general searches across artists,
albums, tracks, genres, and musicians, and precise album searches by artist.
Listening tools provide aggregate all-time track, album, artist, and genre
rankings, recent timestamped play
history, bounded period totals and rankings, and albums with no recorded plays.
The similarity tool resolves one exact reference track, delegates ranking to
the existing pgvector index and configured refinement/personalization settings,
and returns no more than ten matches.
Date-limited answers use counted Sonotheque play events. All-time aggregate
answers can additionally include statistics imported from file tags because
those historical imports do not contain one timestamp for every individual
play.
Tool results contain display metadata and Sonotheque navigation paths, not
physical file locations.

The model does not receive database credentials, SQL, filesystem paths,
provider secrets, arbitrary HTTP access, or playable stream payloads. The
backend constructs similarity playback previews from verified catalog records,
and only the browser can apply them after explicit confirmation. Other queue,
playlist, and playback mutations remain unavailable to the model.
