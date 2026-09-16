<template>
  <div class="max-w-6xl mx-auto px-4 py-6">
    <!-- Loading -->
    <div v-if="loading" class="text-center py-16 text-gray-400">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-green-600 mx-auto mb-4"></div>
      <p>Loading event...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-16">
      <p class="text-red-500 text-lg">{{ error }}</p>
      <button
        @click="fetchEvent"
        class="mt-4 px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
      >
        Retry
      </button>
    </div>

    <!-- Content -->
    <div v-else-if="event" class="space-y-8">
      <!-- Banner -->
      <div class="relative rounded-2xl overflow-hidden shadow-lg cursor-pointer" @click="openLightbox(event.banner ? 0 : -1)">
        <img
          v-if="event.banner"
          :src="bannerUrl"
          :alt="event.title"
          class="w-full h-64 md:h-96 object-cover"
        />
        <div v-else class="w-full h-64 md:h-96 bg-gray-200 flex items-center justify-center">
          <span class="text-gray-400 text-lg">No banner</span>
        </div>
        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-6">
          <div class="flex flex-wrap items-center gap-3">
            <span
              class="px-3 py-1 rounded-full text-xs font-medium text-white"
              :class="{
                'bg-green-600': event.status === 'published',
                'bg-yellow-600': event.status === 'draft',
                'bg-red-600': event.status === 'cancelled',
                'bg-gray-600': !event.status,
              }"
            >
              {{ event.status?.toUpperCase() }}
            </span>
            <span
              v-if="event.is_trending"
              class="px-3 py-1 rounded-full text-xs font-medium bg-orange-500 text-white"
            >
              🔥 Trending
            </span>
            <span
              v-if="event.images && event.images.length > 0"
              class="px-3 py-1 rounded-full text-xs font-medium bg-white/20 text-white backdrop-blur-sm"
            >
              📷 {{ event.images.length }} photo{{ event.images.length > 1 ? 's' : '' }}
            </span>
          </div>
        </div>
        <div class="absolute top-4 right-4 bg-black/40 backdrop-blur-sm text-white text-xs px-3 py-1 rounded-full">
          Click to enlarge
        </div>
      </div>

      <!-- Lightbox -->
      <div
        v-if="lightboxIndex >= 0"
        class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center"
        @click.self="closeLightbox"
      >
        <button
          @click="closeLightbox"
          class="absolute top-4 right-4 text-white/70 hover:text-white text-3xl leading-none"
        >
          &times;
        </button>
        <button
          v-if="lightboxIndex > 0"
          @click.stop="prevImage"
          class="absolute left-4 top-1/2 -translate-y-1/2 bg-white/20 hover:bg-white/40 text-white rounded-full p-3"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </button>
        <button
          v-if="lightboxIndex < imageCount - 1"
          @click.stop="nextImage"
          class="absolute right-4 top-1/2 -translate-y-1/2 bg-white/20 hover:bg-white/40 text-white rounded-full p-3"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </button>
        <div class="text-center">
          <img
            :src="lightboxImage"
            :alt="event.title"
            class="max-w-[90vw] max-h-[80vh] object-contain rounded-lg"
          />
          <p class="text-white/60 text-sm mt-3">
            {{ lightboxIndex + 1 }} / {{ imageCount }}
          </p>
        </div>
      </div>

      <!-- Title & Actions -->
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div class="flex-1">
          <h1 class="text-3xl md:text-4xl font-bold text-gray-900">{{ event.title }}</h1>
          <div class="flex flex-wrap items-center gap-4 mt-3 text-gray-500 text-sm">
            <span v-if="event.category" class="flex items-center gap-1">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
              </svg>
              {{ event.category.name }}
            </span>
            <span v-if="event.organizer" class="flex items-center gap-1">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
              {{ event.organizer.company_name }}
            </span>
          </div>
        </div>
        <div class="flex gap-3 flex-shrink-0">
          <button
            v-if="authUser"
            @click="toggleFavorite"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors flex items-center gap-2"
            :class="isFavorited ? 'text-red-500 border-red-200 bg-red-50' : ''"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="!isFavorited" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
            </svg>
            {{ isFavorited ? 'Favorited' : 'Favorite' }}
          </button>
        </div>
      </div>

      <!-- Description -->
      <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
        <h2 class="text-lg font-semibold mb-3">About this event</h2>
        <p class="text-gray-600 leading-relaxed whitespace-pre-line">{{ event.description }}</p>
      </div>

      <!-- Date/Time/Venue Grid -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Date -->
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <h3 class="font-semibold text-gray-900">Date</h3>
          </div>
          <p class="text-gray-600 text-sm">
            {{ formatDate(event.start_date) }}
          </p>
          <p class="text-gray-500 text-xs mt-1">
            to {{ formatDate(event.end_date) }}
          </p>
        </div>

        <!-- Time -->
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h3 class="font-semibold text-gray-900">Time</h3>
          </div>
          <p class="text-gray-600 text-sm">
            {{ event.start_time }} – {{ event.end_time }}
          </p>
        </div>

        <!-- Venue -->
        <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
          <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <h3 class="font-semibold text-gray-900">Venue</h3>
          </div>
          <p v-if="event.venue" class="text-gray-600 text-sm font-medium">
            {{ event.venue.name }}
          </p>
          <p v-if="event.venue" class="text-gray-500 text-xs mt-1">
            {{ event.venue.address }}<br />
            {{ event.venue.city }}, {{ event.venue.province }}<br />
            Capacity: {{ event.venue.capacity }}
          </p>
        </div>
      </div>

      <!-- Images Gallery -->
      <div v-if="event.images && event.images.length > 0" class="space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold">Photos</h2>
          <span class="text-sm text-gray-500">{{ event.images.length }} photo{{ event.images.length > 1 ? 's' : '' }}</span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
          <div
            v-for="(img, idx) in sortedImages"
            :key="img.id"
            class="rounded-xl overflow-hidden shadow-sm border border-gray-200 cursor-pointer group"
            @click="openLightbox(idx)"
          >
            <img
              :src="imageUrl(img.image)"
              :alt="event.title"
              class="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300"
            />
            <div class="bg-white p-2">
              <p class="text-xs text-gray-500 truncate">{{ img.image?.split('/').pop() }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Ticket Types -->
      <div class="space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold">Tickets</h2>
          <span v-if="event.ticketTypes" class="text-sm text-gray-500">
            {{ event.ticketTypes.reduce((sum, tt) => sum + (tt.quantity - tt.sold_quantity), 0) }} tickets available
          </span>
        </div>

        <div v-if="event.ticketTypes && event.ticketTypes.length > 0" class="space-y-3">
          <div
            v-for="tt in event.ticketTypes"
            :key="tt.id"
            class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 flex flex-col md:flex-row md:items-center md:justify-between gap-4"
          >
            <div class="flex-1">
              <div class="flex items-center gap-3">
                <h3 class="font-semibold text-gray-900">{{ tt.name }}</h3>
                <span
                  class="px-2 py-0.5 rounded text-xs font-medium"
                  :class="{
                    'bg-green-100 text-green-700': tt.status === 'active',
                    'bg-red-100 text-red-700': tt.status === 'inactive',
                    'bg-gray-100 text-gray-600': tt.status !== 'active' && tt.status !== 'inactive',
                  }"
                >
                  {{ tt.status?.toUpperCase() }}
                </span>
              </div>
              <p v-if="tt.description" class="text-sm text-gray-500 mt-1">{{ tt.description }}</p>
              <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                <span class="font-bold text-gray-900 text-lg">{{ formatPrice(tt.price) }}</span>
                <span>{{ tt.quantity - tt.sold_quantity }} available</span>
                <span v-if="tt.sold_quantity > 0">{{ tt.sold_quantity }} sold</span>
              </div>
            </div>
            <div class="flex-shrink-0">
              <button
                v-if="tt.status === 'active' && (tt.quantity - tt.sold_quantity) > 0"
                @click="startCheckout(tt)"
                class="px-6 py-2.5 bg-green-600 text-white rounded-lg font-medium hover:bg-green-700 transition-colors"
              >
                Buy Now
              </button>
              <span
                v-else
                class="px-6 py-2.5 bg-gray-100 text-gray-400 rounded-lg font-medium text-sm"
              >
                Sold Out
              </span>
            </div>
          </div>
        </div>

        <div v-else class="text-center py-8 text-gray-400 bg-white rounded-xl border border-gray-100">
          No tickets available for this event.
        </div>
      </div>

      <!-- You Might Also Like -->
      <section
        v-if="recommendations.length > 0"
        class="rounded-2xl bg-[#0b0f19] p-6 md:p-8 border border-white/5"
      >
        <div class="flex items-center justify-between mb-5">
          <div class="flex items-center gap-3">
            <span class="h-6 w-1.5 bg-green-500 rounded-full"></span>
            <h2 class="text-xl md:text-2xl font-bold text-white">You Might Also Like</h2>
          </div>
          <div class="flex gap-2">
            <button
              @click="scrollRecs(-1)"
              class="w-9 h-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors"
              aria-label="Previous recommendations"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </button>
            <button
              @click="scrollRecs(1)"
              class="w-9 h-9 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors"
              aria-label="Next recommendations"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </button>
          </div>
        </div>

        <div
          ref="recScroller"
          class="flex gap-4 overflow-x-auto pb-2 snap-x scroll-smooth"
        >
          <router-link
            v-for="rec in recommendations"
            :key="rec.id"
            :to="`/events/${rec.id}`"
            class="group w-40 md:w-44 flex-shrink-0 snap-start"
          >
            <div
              class="relative aspect-[2/3] rounded-xl overflow-hidden bg-gray-800 border border-white/10 group-hover:border-green-500/60 transition-colors"
            >
              <img
                v-if="rec.primary_image"
                :src="imageUrl(rec.primary_image.image)"
                :alt="rec.title"
                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                loading="lazy"
              />
              <div
                v-else
                class="w-full h-full flex items-center justify-center bg-gradient-to-br from-gray-800 to-gray-900"
              >
                <svg class="w-10 h-10 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                </svg>
              </div>
              <div
                class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-black/60 backdrop-blur-sm text-white text-[10px] font-medium"
              >
                {{ formatShortDate(rec.start_date) }}
              </div>
              <div
                v-if="rec.primary_image?.is_primary"
                class="absolute top-2 right-2 px-2 py-0.5 rounded-full bg-green-600 text-white text-[10px] font-semibold"
              >
                Poster
              </div>
              <div class="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/80 to-transparent"></div>
              <div class="absolute bottom-2 left-2 right-2">
                <p class="text-white text-sm font-semibold line-clamp-2 leading-snug">{{ rec.title }}</p>
              </div>
            </div>
            <p class="mt-2 text-xs text-gray-400 truncate">
              {{ rec.venue?.city || rec.venue?.name || 'No venue' }}
            </p>
          </router-link>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';

const props = defineProps({
  eventId: { type: [String, Number], default: null },
  apiUrl: { type: String, default: '/api' },
});

const emit = defineEmits(['checkout', 'favorited', 'error']);

const route = useRoute();
const loading = ref(true);
const error = ref('');
const event = ref(null);
const isFavorited = ref(false);
const authUser = ref(null);
const lightboxIndex = ref(-1);

const eventId = computed(() => props.eventId ?? route.params.id);
const recommendations = ref([]);
const recScroller = ref(null);

const sortedImages = computed(() => {
  if (!event.value?.images) return [];
  return [...event.value.images].sort((a, b) => a.sort_order - b.sort_order);
});

const imageCount = computed(() => sortedImages.value.length);

const lightboxImage = computed(() => {
  const idx = lightboxIndex.value;
  if (idx < 0 || idx >= sortedImages.value.length) return '';
  return imageUrl(sortedImages.value[idx].image);
});

function bannerUrl() {
  if (!event.value?.banner) return '';
  return `/storage/${event.value.banner}`;
}

function imageUrl(path) {
  if (!path) return '';
  return `/storage/${path}`;
}

function formatPrice(value) {
  if (value === null || value === undefined || isNaN(value)) return '0.00';
  return Number(value).toFixed(2);
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  return date.toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
}

function formatShortDate(dateStr) {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

async function fetchRecommendations() {
  try {
    const response = await fetch(`${props.apiUrl}/events/${eventId.value}/recommendations?limit=10`);
    if (!response.ok) return;
    const data = await response.json();
    recommendations.value = data.data || [];
  } catch { /* ignore */ }
}

function scrollRecs(dir) {
  if (recScroller.value) {
    recScroller.value.scrollBy({ left: dir * 280, behavior: 'smooth' });
  }
}

async function fetchEvent() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch(`${props.apiUrl}/events/${eventId.value}`);
    if (!response.ok) {
      throw new Error('Event not found.');
    }
    const data = await response.json();
    event.value = data.data;
  } catch (err) {
    error.value = err.message || 'Failed to load event.';
    emit('error', err);
  } finally {
    loading.value = false;
  }
}

async function checkFavorite() {
  try {
    const response = await fetch(`${props.apiUrl}/user/favorites`);
    if (response.ok) {
      const data = await response.json();
      const favorites = data.data || [];
      isFavorited.value = favorites.some((f) => f.event_id == eventId.value);
    }
  } catch { /* ignore */ }
}

async function loadAuthUser() {
  try {
    const response = await fetch(`${props.apiUrl}/user`);
    if (response.ok) {
      const data = await response.json();
      authUser.value = data.data;
    }
  } catch { /* ignore */ }
}

async function toggleFavorite() {
  if (!authUser.value) return;
  try {
    if (isFavorited.value) {
      const response = await fetch(`${props.apiUrl}/events/${eventId.value}/favorite`, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json' },
      });
      if (response.ok) {
        isFavorited.value = false;
      }
    } else {
      const response = await fetch(`${props.apiUrl}/events/${eventId.value}/favorite`, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
      });
      if (response.ok) {
        isFavorited.value = true;
      }
    }
  } catch { /* ignore */ }
}

function openLightbox(idx) {
  if (idx < 0) return;
  lightboxIndex.value = idx;
}

function closeLightbox() {
  lightboxIndex.value = -1;
}

function prevImage() {
  if (lightboxIndex.value > 0) {
    lightboxIndex.value--;
  }
}

function nextImage() {
  if (lightboxIndex.value < imageCount.value - 1) {
    lightboxIndex.value++;
  }
}

function startCheckout(ticketType) {
  emit('checkout', { eventId: event.value.id, ticketTypeId: ticketType.id, ticketType });
}

function onKeydown(e) {
  if (lightboxIndex.value < 0) return;
  if (e.key === 'Escape') closeLightbox();
  if (e.key === 'ArrowLeft') prevImage();
  if (e.key === 'ArrowRight') nextImage();
}

onMounted(async () => {
  await Promise.all([fetchEvent(), loadAuthUser(), fetchRecommendations()]);
  if (event.value) {
    await checkFavorite();
  }
  window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown);
});
</script>
