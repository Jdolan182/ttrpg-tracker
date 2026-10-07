<script setup lang="ts">
// A one-series column chart for the admin page: one bar per day, thin, with a tooltip on hover. The
// title names the series, so there's no legend; the day-by-day table on the page is its text version.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Bar {
    // Short label under the axis, e.g. "7 Oct".
    label: string;
    value: number;
    // Extra lines for the tooltip, e.g. "3 logged in".
    details?: string[];
}

const props = defineProps<{
    bars: Bar[];
    // What one bar counts, for the tooltip: "visitors", "sign-ups"…
    unit: string;
}>();

// Drawn at the box's real width (one unit = one pixel), so labels stay 10px and bars stay thin
// whether the chart is half the page or all of it.
const box = ref<HTMLElement | null>(null);
const width = ref(600);
let observer: ResizeObserver | undefined;
onMounted(() => {
    observer = new ResizeObserver(([entry]) => (width.value = Math.max(240, Math.round(entry.contentRect.width))));
    if (box.value) observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());

const height = 170;
const pad = { top: 10, right: 6, bottom: 22, left: 30 };
const plotW = computed(() => width.value - pad.left - pad.right);
const plotH = height - pad.top - pad.bottom;

// A round top to the scale (1, 2, 5, 10, 20…), and never below 4, so a quiet week isn't drawn as huge.
const top = computed(() => {
    const max = Math.max(4, ...props.bars.map((b) => b.value));
    const magnitude = 10 ** Math.floor(Math.log10(max));
    return [1, 2, 5, 10].map((step) => step * magnitude).find((n) => n >= max) ?? max;
});
// Everything here is a count, so a half-way line only when it's a whole number (no "2.5 visitors").
const gridValues = computed(() => (Number.isInteger(top.value / 2) ? [0, top.value / 2, top.value] : [0, top.value]));

const band = computed(() => plotW.value / Math.max(1, props.bars.length));
// Thin bars, at most 24px, with air between them.
const barW = computed(() => Math.min(24, Math.max(2, band.value - 4)));
const y = (value: number) => pad.top + plotH - (value / top.value) * plotH;

// Rounded at the data end (4px), square at the baseline.
const barPath = (index: number, value: number) => {
    const x = pad.left + band.value * index + (band.value - barW.value) / 2;
    const h = (value / top.value) * plotH;
    const r = Math.min(4, barW.value / 2, h);
    const base = pad.top + plotH;
    const t = base - h;
    return `M${x},${base} V${t + r} Q${x},${t} ${x + r},${t} H${x + barW.value - r} Q${x + barW.value},${t} ${x + barW.value},${t + r} V${base} Z`;
};

// Labels under a few bars only, so they never collide: the first, every seventh and the last.
const showLabel = (index: number) => index === 0 || index === props.bars.length - 1 || (index % 7 === 0 && props.bars.length - 1 - index > 3);

const hovered = ref<number | null>(null);
const tip = computed(() => (hovered.value === null ? null : props.bars[hovered.value]));
const tipLeft = computed(() => (hovered.value === null ? 0 : ((pad.left + band.value * (hovered.value + 0.5)) / width.value) * 100));
</script>

<template>
    <div ref="box" class="relative">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            :height="height"
            class="w-full overflow-visible"
            role="img"
            :aria-label="`Bar chart of ${unit} per day`"
        >
            <!-- Recessive grid and its numbers -->
            <g v-for="value in gridValues" :key="value">
                <line
                    :x1="pad.left"
                    :x2="width - pad.right"
                    :y1="y(value)"
                    :y2="y(value)"
                    class="stroke-border"
                    :stroke-dasharray="value === 0 ? undefined : '2 3'"
                />
                <text :x="pad.left - 6" :y="y(value) + 3" text-anchor="end" class="fill-muted-foreground text-[10px] tabular-nums">
                    {{ value }}
                </text>
            </g>

            <path
                v-for="(bar, i) in bars"
                v-show="bar.value > 0"
                :key="`b${i}`"
                :d="barPath(i, bar.value)"
                class="fill-primary transition-opacity"
                :class="hovered !== null && hovered !== i ? 'opacity-50' : ''"
            />

            <text
                v-for="(bar, i) in bars"
                v-show="showLabel(i)"
                :key="`l${i}`"
                :x="pad.left + band * (i + 0.5)"
                :y="height - 6"
                text-anchor="middle"
                class="fill-muted-foreground text-[10px]"
            >
                {{ bar.label }}
            </text>

            <!-- Hover targets: the whole column, bigger than the bar -->
            <rect
                v-for="(bar, i) in bars"
                :key="`h${i}`"
                :x="pad.left + band * i"
                :y="pad.top"
                :width="band"
                :height="plotH"
                fill="transparent"
                @mouseenter="hovered = i"
                @mouseleave="hovered = null"
            />
        </svg>

        <div
            v-if="tip"
            class="pointer-events-none absolute top-0 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md border border-border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-md"
            :style="{ left: `${tipLeft}%` }"
        >
            <p class="font-medium">{{ tip.label }}</p>
            <p class="tabular-nums">{{ tip.value }} {{ unit }}</p>
            <p v-for="line in tip.details ?? []" :key="line" class="text-muted-foreground">{{ line }}</p>
        </div>
    </div>
</template>
