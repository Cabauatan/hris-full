<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useApplicantStore } from '@/stores/applicant'
import { useInterviewStore } from '@/stores/interview'
import { interviewPath } from '@/realtime/interview'
import Button from 'primevue/button'
import Message from 'primevue/message'

const route = useRoute(),
  router = useRouter()
const auth = useAuthStore(),
  applicant = useApplicantStore(),
  call = useInterviewStore()
const scope = computed(() => (route.meta.applicant ? 'applicant' : 'user'))
const actorId = computed(() => (scope.value === 'applicant' ? applicant.user?.id : auth.user?.id))
const interview = ref(null),
  loading = ref(false),
  error = ref('')
let version = 0,
  controller
const remoteVideos = new Map()
const title = computed(() =>
  interview.value?.round === 'final' ? 'Final interview' : 'Initial interview',
)
const participants = computed(() => call.room?.participants || [])
const schedule = computed(() =>
  interview.value?.scheduled_at ? new Date(interview.value.scheduled_at).toLocaleString() : '',
)
const returnPath = computed(() =>
  scope.value === 'applicant' ? '/applicant/applications' : '/recruitment',
)

async function load() {
  const current = ++version
  controller?.abort()
  call.leave()
  interview.value = null
  error.value = ''
  loading.value = true
  controller = new AbortController()
  try {
    const { data } = await http.get(interviewPath(scope.value, route.params.interviewId), {
      signal: controller.signal,
    })
    if (current === version) interview.value = data.data
  } catch (failure) {
    if (current === version) error.value = apiError(failure).message
  } finally {
    if (current === version) loading.value = false
  }
}
function video(element, stream, key) {
  if (key) {
    if (element) remoteVideos.set(key, element)
    else remoteVideos.delete(key)
  }
  if (!element) return
  if (element.srcObject !== stream) element.srcObject = stream || null
  if (stream) element.play()?.catch(() => {})
}
function enableSound() {
  for (const element of remoteVideos.values()) element.play()?.catch(() => {})
}
function leave() {
  call.leave()
  router.push(returnPath.value)
}
function stop() {
  call.leave()
}
watch(() => [route.params.interviewId, scope.value], load, { immediate: true })
watch(
  () => [actorId.value, scope.value === 'applicant' ? applicant.authenticated : auth.authenticated],
  stop,
)
onMounted(() => window.addEventListener('beforeunload', stop))
onBeforeUnmount(() => {
  version += 1
  controller?.abort()
  call.leave()
  window.removeEventListener('beforeunload', stop)
})
</script>

<template>
  <section class="interview-room">
    <div class="room-heading">
      <div>
        <p class="room-eyebrow">Recruitment interview</p>
        <h1>{{ title }}</h1>
        <p v-if="interview">{{ schedule }} · {{ interview.duration_minutes }} minutes</p>
      </div>
      <Button label="Back" icon="pi pi-arrow-left" severity="secondary" outlined @click="leave" />
    </div>
    <Message v-if="loading" severity="info" :closable="false">Loading interview…</Message>
    <Message v-if="error || call.error" severity="error" :closable="false">{{
      error || call.error
    }}</Message>
    <div v-if="interview && !call.joined && !call.joining" class="room-lobby">
      <i class="pi pi-video" aria-hidden="true"></i>
      <h2>Ready for your interview?</h2>
      <p>
        Join to request camera and microphone access. You can mute, turn off your camera, or leave
        at any time.
      </p>
      <p>{{ interview.interviewers?.map((person) => person.name).join(', ') }}</p>
      <Button
        label="Join interview"
        icon="pi pi-video"
        :disabled="!interview.abilities?.join"
        @click="call.join(scope, interview.id, actorId)"
      />
      <p v-if="!interview.abilities?.join">
        The room opens 15 minutes before the schedule. Only assigned participants can join.
      </p>
      <Button label="Refresh availability" severity="secondary" text @click="load" />
    </div>
    <div v-if="call.joining || call.joined">
      <p v-if="call.joining" role="status">
        Waiting for browser permission or connecting to the room…
      </p>
      <div class="room-grid">
        <article class="room-tile">
          <video
            :ref="(element) => video(element, call.localStream)"
            autoplay
            playsinline
            muted
            aria-label="Your camera preview"
          ></video
          ><span
            >You{{ !call.cameraEnabled ? ' · Camera off' : ''
            }}{{ !call.micEnabled ? ' · Muted' : '' }}</span
          >
        </article>
        <article
          v-for="person in participants.filter((item) => item.key !== call.room?.actor.key)"
          :key="person.key"
          class="room-tile"
        >
          <video
            :ref="(element) => video(element, call.remoteStreams[person.key], person.key)"
            autoplay
            playsinline
            :aria-label="person.name"
          ></video>
          <div v-if="!call.remoteStreams[person.key]" class="room-waiting">
            <i class="pi pi-user" aria-hidden="true"></i>
            <p>
              {{
                call.peerStates[person.key] === 'failed'
                  ? 'Connection failed — leave and rejoin'
                  : 'Waiting to connect'
              }}
            </p>
          </div>
          <span>{{ person.name }} · {{ call.peerStates[person.key] || 'waiting' }}</span>
        </article>
      </div>
      <div class="room-controls">
        <Button
          label="Enable sound"
          icon="pi pi-volume-up"
          severity="secondary"
          :disabled="!call.joined"
          @click="enableSound"
        />
        <Button
          :label="call.micEnabled ? 'Mute' : 'Unmute'"
          :icon="call.micEnabled ? 'pi pi-microphone' : 'pi pi-volume-off'"
          severity="secondary"
          :disabled="!call.localStream"
          @click="call.toggleMic"
        />
        <Button
          :label="call.cameraEnabled ? 'Camera off' : 'Camera on'"
          icon="pi pi-video"
          severity="secondary"
          :disabled="!call.localStream"
          @click="call.toggleCamera"
        />
        <Button
          label="Leave interview"
          icon="pi pi-sign-out"
          severity="danger"
          @click="call.leave"
        />
      </div>
    </div>
  </section>
</template>

<style scoped>
.interview-room {
  max-width: 1200px;
  margin: auto;
  padding: 1.5rem;
}
.room-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.room-eyebrow {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--p-primary-color);
}
h1 {
  margin: 0.25rem 0;
}
.room-lobby {
  padding: 3rem 1rem;
  text-align: center;
}
.room-lobby > i {
  font-size: 3rem;
  color: var(--p-primary-color);
}
.room-lobby p {
  max-width: 540px;
  margin: 1rem auto;
  color: var(--p-text-muted-color);
}
.room-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1rem;
  margin-top: 1rem;
}
.room-tile {
  position: relative;
  background: #172033;
  border-radius: 1rem;
  overflow: hidden;
  aspect-ratio: 16/10;
}
.room-tile video {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.room-tile > span {
  position: absolute;
  bottom: 0.6rem;
  left: 0.6rem;
  right: 0.6rem;
  color: white;
  font-size: 0.85rem;
  background: #0009;
  padding: 0.4rem 0.65rem;
  border-radius: 0.5rem;
}
.room-waiting {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #d1d5db;
  text-align: center;
}
.room-waiting i {
  font-size: 2rem;
}
.room-controls {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.75rem;
  margin: 1.5rem 0;
}
</style>
