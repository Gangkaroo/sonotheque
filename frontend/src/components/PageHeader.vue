<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  icon: { type: String, required: true },
})
</script>

<template>
  <header class="page-header mb-8">
    <v-avatar color="primary" variant="tonal" size="52">
      <v-icon :icon="icon" size="28" />
    </v-avatar>
    <div>
      <h1 class="text-h4 font-weight-bold">{{ title }}</h1>
      <p class="text-body-1 text-medium-emphasis mt-1">{{ description }}</p>
    </div>
  </header>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  gap: 18px;
}
</style>
