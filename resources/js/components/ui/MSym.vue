<script setup>
// Icône Material Symbols (équivalent du composant Sym des maquettes).
const props = defineProps({
    n: { type: String, required: true },
    fill: { type: Boolean, default: false },
    size: { type: [Number, String], default: null },
});

const style = props.size != null
    ? { fontSize: typeof props.size === 'number' ? `${props.size}px` : props.size }
    : null;
</script>

<template>
    <span class="msym" :class="{ fill }" :style="style">{{ n }}</span>
</template>
