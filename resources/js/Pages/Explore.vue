<template>
  <div class="min-h-screen bg-gray-900">
    <!-- Search Bar -->
    <div class="container mx-auto px-4 py-6">
      <div class="relative">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search videos..."
          class="w-full px-4 py-2 bg-gray-800 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          @input="debounceSearch"
        />
        <div v-if="isSearching" class="absolute right-3 top-2">
          <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-500"></div>
        </div>
      </div>
    </div>

    <!-- Video Grid -->
    <div class="container mx-auto px-4 pb-8">
      <div v-if="loading" class="flex justify-center items-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>

      <div v-else-if="videos.length === 0" class="text-center py-12">
        <p class="text-gray-400 text-lg">No videos found</p>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
        <div
          v-for="video in videos"
          :key="video.id"
          class="bg-gray-800 rounded-lg overflow-hidden shadow-lg hover:shadow-xl transition-shadow duration-300"
        >
          <div class="relative aspect-video">
            <video
              :src="video.url"
              class="w-full h-full object-cover"
              preload="metadata"
              @mouseover="video.play()"
              @mouseleave="video.pause()"
            ></video>
          </div>
          <div class="p-4">
            <h3 class="text-white font-semibold truncate">{{ video.name }}</h3>
            <p class="text-gray-400 text-sm mt-1 truncate">{{ video.metadata?.description }}</p>
            <div class="flex flex-wrap gap-2 mt-2">
              <span
                v-for="tag in video.metadata?.tags"
                :key="tag"
                class="px-2 py-1 bg-gray-700 text-gray-300 text-xs rounded-full"
              >
                {{ tag }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Load More -->
      <div v-if="hasMorePages" class="flex justify-center mt-8">
        <button
          @click="loadMore"
          class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-300"
          :disabled="loadingMore"
        >
          <span v-if="loadingMore">Loading...</span>
          <span v-else>Load More</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import debounce from 'lodash/debounce'

const videos = ref([])
const loading = ref(true)
const loadingMore = ref(false)
const searchQuery = ref('')
const isSearching = ref(false)
const currentPage = ref(1)
const hasMorePages = ref(true)

const fetchVideos = async (page = 1, search = '') => {
  try {
    const response = await axios.get('/api/v1/media', {
      params: {
        page,
        search,
        per_page: 12
      }
    })

    if (page === 1) {
      videos.value = response.data.data
    } else {
      videos.value = [...videos.value, ...response.data.data]
    }

    hasMorePages.value = response.data.meta.current_page < response.data.meta.last_page
    currentPage.value = page
  } catch (error) {
    console.error('Error fetching videos:', error)
  } finally {
    loading.value = false
    loadingMore.value = false
    isSearching.value = false
  }
}

const loadMore = () => {
  if (!loadingMore.value && hasMorePages.value) {
    loadingMore.value = true
    fetchVideos(currentPage.value + 1, searchQuery.value)
  }
}

const debounceSearch = debounce(() => {
  isSearching.value = true
  loading.value = true
  currentPage.value = 1
  fetchVideos(1, searchQuery.value)
}, 300)

onMounted(() => {
  fetchVideos()
})
</script> 