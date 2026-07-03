<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  icon: { type: String, required: true },
})
</script>

<template>
  <v-card class="empty-state pa-10 text-center" border rounded="xl">
    <v-icon color="primary" :icon="icon" size="72" />
    <h2 class="text-h5 mt-5">{{ title }}</h2>
    <p class="text-body-1 text-medium-emphasis mt-2">{{ description }}</p>
  </v-card>
</template>

<style scoped>
.empty-state {
  border-style: dashed;
}
</style>
