<template>
  <section class="bg-[#0b0f19] border border-white/5 rounded-2xl p-6">
    <div class="flex items-center justify-between mb-1">
      <h2 class="text-xl font-bold text-white flex items-center gap-3">
        <span class="h-6 w-1.5 bg-green-500 rounded-full"></span>
        Event Images
      </h2>
      <span class="text-xs text-gray-400 bg-white/5 border border-white/10 rounded-full px-3 py-1">
        {{ images.length }} image{{ images.length === 1 ? '' : 's' }}
      </span>
    </div>
    <p class="text-sm text-gray-400 mb-6">
      The image with <span class="text-white font-medium">sort order 1</span> is the main
      poster shown on the event page.
    </p>

    <!-- Upload -->
    <div class="bg-white/5 border border-dashed border-white/15 rounded-xl p-5 mb-6">
      <div class="flex flex-col md:flex-row md:items-center gap-4">
        <input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/webp,image/jpg"
          class="block w-full text-sm text-gray-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-green-600 file:text-white hover:file:bg-green-700 cursor-pointer"
          @change="onFileSelected"
        />
        <button
          @click="upload"
          :disabled="!selectedFile || uploading"
          class="flex-shrink-0 px-5 py-2 rounded-lg font-medium transition-colors"
          :class="selectedFile && !uploading
            ? 'bg-green-600 text-white hover:bg-green-700'
            : 'bg-white/5 text-gray-500 cursor-not-allowed'"
        >
          {{ uploading ? 'Uploading…' : 'Upload image' }}
        </button>
      </div>
      <p v-if="selectedFile" class="mt-3 text-xs text-gray-400 truncate">
        Selected: {{ selectedFile.name }}
      </p>
    </div>

    <!-- Error / success -->
    <p v-if="error" class="text-sm text-red-400 mb-4">{{ error }}</p>
    <p v-if="success" class="text-sm text-green-400 mb-4">{{ success }}</p>

    <!-- Image list -->
    <div v-if="loading" class="text-center py-10 text-gray-400">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-green-600 mx-auto mb-3"></div>
      <p class="text-sm">Loading images…</p>
    </div>

    <div v-else-if="images.length === 0" class="text-center py-10 text-gray-500">
      <p class="text-sm">No images yet. Upload one above.</p>
    </div>

    <div v-else class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
      <div
        v-for="(img, idx) in images"
        :key="img.id"
        class="group rounded-xl overflow-hidden border transition-colors"
        :class="img.sort_order === 1
          ? 'border-green-500/60 ring-1 ring-green-500/40'
          : 'border-white/10'"
      >
        <!-- Poster thumbnail (2:3) -->
        <div class="relative aspect-[2/3] bg-gray-800">
          <img
            :src="imageUrl(img.image)"
            :alt="`Image ${img.sort_order}`"
            class="w-full h-full object-cover"
            loading="lazy"
          />
          <span
            class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-[11px] font-bold"
            :class="img.sort_order === 1
              ? 'bg-green-600 text-white'
              : 'bg-black/60 text-white backdrop-blur-sm'"
          >
            {{ img.sort_order === 1 ? '★ Poster' : `#${img.sort_order}` }}
          </span>

          <!-- Order controls -->
          <div class="absolute top-2 right-2 flex flex-col gap-1">
            <button
              @click="moveImage(img, -1)"
              :disabled="idx === 0"
              class="w-7 h-7 flex items-center justify-center rounded-full bg-black/60 hover:bg-black/80 text-white transition-colors disabled:opacity-30"
              aria-label="Move up"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
              </svg>
            </button>
            <button
              @click="moveImage(img, 1)"
              :disabled="idx === images.length - 1"
              class="w-7 h-7 flex items-center justify-center rounded-full bg-black/60 hover:bg-black/80 text-white transition-colors disabled:opacity-30"
              aria-label="Move down"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
          </div>
        </div>

        <!-- Actions -->
        <div class="p-2.5 bg-[#0e1420] space-y-2">
          <button
            v-if="img.sort_order !== 1"
            @click="setPrimary(img)"
            class="w-full py-1.5 rounded-lg text-xs font-medium bg-white/5 text-gray-300 hover:bg-green-600 hover:text-white transition-colors"
          >
            Set as poster
          </button>
          <button
            @click="removeImage(img)"
            class="w-full py-1.5 rounded-lg text-xs font-medium bg-white/5 text-gray-300 hover:bg-red-600 hover:text-white transition-colors"
          >
            Delete
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';

const props = defineProps({
  eventId: { type: [String, Number], required: true },
  apiUrl: { type: String, default: '/api' },
});

const images = ref([]);
const loading = ref(true);
const uploading = ref(false);
const busy = ref(false);
const error = ref('');
const success = ref('');
const fileInput = ref(null);
const selectedFile = ref(null);

function imageUrl(path) {
  if (!path) return '';
  return `/storage/${path}`;
}

async function fetchImages() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch(`${props.apiUrl}/events/${props.eventId}/images`);
    if (!response.ok) throw new Error('Failed to load images.');
    const data = await response.json();
    images.value = [...(data.data || [])].sort((a, b) => a.sort_order - b.sort_order);
  } catch (err) {
    error.value = err.message || 'Failed to load images.';
  } finally {
    loading.value = false;
  }
}

function onFileSelected(e) {
  selectedFile.value = e.target.files?.[0] || null;
}

async function upload() {
  if (!selectedFile.value || uploading.value) return;
  uploading.value = true;
  error.value = '';
  success.value = '';
  try {
    const formData = new FormData();
    formData.append('event_id', props.eventId);
    formData.append('image', selectedFile.value);

    const response = await fetch(`${props.apiUrl}/admin/event-images`, {
      method: 'POST',
      body: formData,
      headers: { 'Accept': 'application/json' },
    });

    if (!response.ok) {
      const errData = await response.json().catch(() => ({}));
      throw new Error(errData.message || 'Upload failed.');
    }

    success.value = 'Image uploaded.';
    selectedFile.value = null;
    if (fileInput.value) fileInput.value.value = '';
    await fetchImages();
  } catch (err) {
    error.value = err.message || 'Upload failed.';
  } finally {
    uploading.value = false;
  }
}

async function saveOrder(payload) {
  busy.value = true;
  error.value = '';
  try {
    const response = await fetch(`${props.apiUrl}/admin/event-images/reorder`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        event_id: props.eventId,
        images: payload,
      }),
    });

    if (!response.ok) throw new Error('Failed to reorder images.');

    const data = await response.json();
    images.value = [...(data.data || [])].sort((a, b) => a.sort_order - b.sort_order);
    success.value = 'Image order updated.';
  } catch (err) {
    error.value = err.message || 'Failed to reorder images.';
    await fetchImages();
  } finally {
    busy.value = false;
  }
}

async function moveImage(img, dir) {
  if (busy.value) return;
  const idx = images.value.findIndex((i) => i.id === img.id);
  const target = idx + dir;
  if (idx < 0 || target < 0 || target >= images.value.length) return;

  const next = images.value.map((i) => ({ ...i }));
  [next[idx], next[target]] = [next[target], next[idx]];

  const payload = next.map((i, position) => ({ id: i.id, sort_order: position + 1 }));
  await saveOrder(payload);
}

async function setPrimary(img) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  success.value = '';
  try {
    const response = await fetch(`${props.apiUrl}/admin/event-images/${img.id}/set-primary`, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
    });
    if (!response.ok) throw new Error('Failed to set primary image.');
    success.value = 'Poster updated.';
    await fetchImages();
  } catch (err) {
    error.value = err.message || 'Failed to set primary image.';
  } finally {
    busy.value = false;
  }
}

async function removeImage(img) {
  if (busy.value) return;
  if (!window.confirm(`Delete image #${img.sort_order}?`)) return;
  busy.value = true;
  error.value = '';
  success.value = '';
  try {
    const response = await fetch(`${props.apiUrl}/admin/event-images/${img.id}`, {
      method: 'DELETE',
      headers: { 'Accept': 'application/json' },
    });
    if (!response.ok) throw new Error('Failed to delete image.');
    success.value = 'Image deleted.';
    await fetchImages();
  } catch (err) {
    error.value = err.message || 'Failed to delete image.';
  } finally {
    busy.value = false;
  }
}

onMounted(fetchImages);
</script>