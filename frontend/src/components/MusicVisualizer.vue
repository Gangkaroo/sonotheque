<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

interface AudioGraph {
  analyser: AnalyserNode
  bands: FrequencyBand[]
  data: Uint8Array<ArrayBuffer>
  source: MediaElementAudioSourceNode
}

interface FrequencyBand {
  end: number
  start: number
}

const BAR_COUNT = 72
const MIN_FREQUENCY = 40
const MAX_FREQUENCY = 18000

const props = withDefaults(defineProps<{
  audioElement: HTMLAudioElement | null
  active: boolean
  enabled: boolean
}>(), {
  audioElement: null,
  active: false,
  enabled: true,
})

const canvas = ref<HTMLCanvasElement | null>(null)
let animationFrame: number | null = null
let graph: AudioGraph | null = null
let resizeObserver: ResizeObserver | null = null

const audioGraphs = new WeakMap<HTMLAudioElement, AudioGraph>()
let sharedAudioContext: AudioContext | null = null

watch(
  () => [props.audioElement, props.enabled, props.active] as const,
  () => {
    if (!props.enabled) {
      stopDrawing()
      drawIdleFrame()
      return
    }

    void prepareVisualizer()
  },
  { flush: 'post' },
)

onMounted(() => {
  resizeObserver = new ResizeObserver(() => {
    resizeCanvas()
    drawIdleFrame()
  })
  if (canvas.value) resizeObserver.observe(canvas.value)
  void prepareVisualizer()
})

onBeforeUnmount(() => {
  stopDrawing()
  resizeObserver?.disconnect()
})

async function prepareVisualizer() {
  await nextTick()
  if (!props.audioElement || !props.enabled || !canvas.value) return

  try {
    graph = graphFor(props.audioElement)
    resizeCanvas()
    if (props.active) {
      await resumeAudioContext()
      startDrawing()
    } else {
      stopDrawing()
      drawIdleFrame()
    }
  } catch {
    stopDrawing()
    drawIdleFrame()
  }
}

function graphFor(element: HTMLAudioElement): AudioGraph {
  const existing = audioGraphs.get(element)
  if (existing) return existing

  const context = audioContext()
  const source = context.createMediaElementSource(element)
  const analyser = context.createAnalyser()
  analyser.fftSize = 1024
  analyser.smoothingTimeConstant = 0.82
  source.connect(analyser)
  analyser.connect(context.destination)

  const created = {
    analyser,
    bands: frequencyBands(analyser.frequencyBinCount, context.sampleRate),
    data: new Uint8Array(analyser.frequencyBinCount),
    source,
  }
  audioGraphs.set(element, created)

  return created
}

function audioContext(): AudioContext {
  if (sharedAudioContext) return sharedAudioContext

  const AudioContextConstructor = window.AudioContext
    ?? (window as typeof window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!AudioContextConstructor) throw new Error('AudioContext is not available.')

  sharedAudioContext = new AudioContextConstructor()

  return sharedAudioContext
}

async function resumeAudioContext() {
  if (sharedAudioContext?.state === 'suspended') {
    await sharedAudioContext.resume()
  }
}

function startDrawing() {
  if (animationFrame !== null) return

  const draw = () => {
    drawFrequencyFrame()
    animationFrame = window.requestAnimationFrame(draw)
  }
  animationFrame = window.requestAnimationFrame(draw)
}

function stopDrawing() {
  if (animationFrame === null) return

  window.cancelAnimationFrame(animationFrame)
  animationFrame = null
}

function resizeCanvas() {
  const element = canvas.value
  if (!element) return

  const width = Math.max(1, Math.floor(element.clientWidth * window.devicePixelRatio))
  const height = Math.max(1, Math.floor(element.clientHeight * window.devicePixelRatio))
  if (element.width === width && element.height === height) return

  element.width = width
  element.height = height
}

function drawFrequencyFrame() {
  const element = canvas.value
  const context = element?.getContext('2d')
  if (!element || !context || !graph) return

  graph.analyser.getByteFrequencyData(graph.data)
  drawBars(context, element, graph.data, graph.bands)
}

function drawIdleFrame() {
  const element = canvas.value
  const context = element?.getContext('2d')
  if (!element || !context) return

  const data = new Uint8Array(96)
  for (let index = 0; index < data.length; index += 1) {
    data[index] = 18 + Math.round(Math.sin(index * 0.42) * 8)
  }
  drawBars(context, element, data)
}

function frequencyBands(frequencyBinCount: number, sampleRate: number): FrequencyBand[] {
  const nyquist = sampleRate / 2
  const maxFrequency = Math.min(MAX_FREQUENCY, nyquist)
  const minFrequency = Math.min(MIN_FREQUENCY, maxFrequency / 2)
  const binSize = nyquist / frequencyBinCount
  const ratio = maxFrequency / minFrequency

  return Array.from({ length: BAR_COUNT }, (_, index) => {
    const lowFrequency = minFrequency * (ratio ** (index / BAR_COUNT))
    const highFrequency = minFrequency * (ratio ** ((index + 1) / BAR_COUNT))
    const start = Math.max(0, Math.floor(lowFrequency / binSize))
    const end = Math.min(frequencyBinCount, Math.max(start + 1, Math.ceil(highFrequency / binSize)))

    return { start, end }
  })
}

function drawBars(
  context: CanvasRenderingContext2D,
  element: HTMLCanvasElement,
  data: ArrayLike<number>,
  bands?: FrequencyBand[],
) {
  const { width, height } = element
  context.clearRect(0, 0, width, height)

  const background = context.createLinearGradient(0, 0, width, height)
  background.addColorStop(0, 'rgba(255, 152, 0, 0.08)')
  background.addColorStop(0.5, 'rgba(255, 111, 0, 0.13)')
  background.addColorStop(1, 'rgba(255, 193, 7, 0.08)')
  context.fillStyle = background
  context.fillRect(0, 0, width, height)

  const barCount = bands?.length ?? Math.min(BAR_COUNT, data.length)
  const gap = Math.max(1, Math.floor(width * 0.004))
  const barWidth = Math.max(2, (width - (gap * (barCount - 1))) / barCount)
  const gradient = context.createLinearGradient(0, height, 0, 0)
  gradient.addColorStop(0, 'rgba(255, 112, 67, 0.38)')
  gradient.addColorStop(0.52, 'rgba(255, 152, 0, 0.82)')
  gradient.addColorStop(1, 'rgba(255, 224, 130, 0.98)')
  context.fillStyle = gradient

  for (let bar = 0; bar < barCount; bar += 1) {
    const value = bandValue(data, bands?.[bar] ?? linearBand(bar, barCount, data.length))
    const shaped = Math.pow(value, 0.74)
    const barHeight = Math.max(height * 0.08, shaped * height * 0.92)
    const x = bar * (barWidth + gap)
    const y = height - barHeight
    const radius = Math.min(barWidth / 2, 8 * window.devicePixelRatio)
    roundedBar(context, x, y, barWidth, barHeight, radius)
  }
}

function linearBand(index: number, count: number, dataLength: number): FrequencyBand {
  const step = dataLength / count
  const start = Math.floor(index * step)
  const end = Math.max(start + 1, Math.ceil((index + 1) * step))

  return { start, end }
}

function bandValue(data: ArrayLike<number>, band: FrequencyBand) {
  let total = 0
  let count = 0

  for (let index = band.start; index < band.end; index += 1) {
    const value = data[index] ?? 0
    total += value * value
    count += 1
  }

  return count ? Math.sqrt(total / count) / 255 : 0
}

function roundedBar(
  context: CanvasRenderingContext2D,
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
) {
  context.beginPath()
  context.moveTo(x, y + height)
  context.lineTo(x, y + radius)
  context.quadraticCurveTo(x, y, x + radius, y)
  context.lineTo(x + width - radius, y)
  context.quadraticCurveTo(x + width, y, x + width, y + radius)
  context.lineTo(x + width, y + height)
  context.closePath()
  context.fill()
}
</script>

<template>
  <canvas
    v-show="enabled"
    ref="canvas"
    aria-hidden="true"
    class="music-visualizer"
  />
</template>

<style scoped>
.music-visualizer {
  border-radius: 14px;
  display: block;
  height: 52px;
  overflow: hidden;
  width: 100%;
}
</style>
