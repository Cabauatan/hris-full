<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { formatValue, labelFor, statusSeverity } from '@/utils/forms'
import { notificationLink } from '@/utils/notifications'

const auth = useAuthStore()
const inbox = useNotificationsStore()
function recordLink(item) {
  return notificationLink(item, auth)
}
onMounted(() => inbox.load())
onBeforeUnmount(() => inbox.cancelList())
</script>

<template>
  <div class="notifications-page">
    <div class="page-heading">
      <div>
        <span class="eyebrow">YOUR INBOX</span>
        <h1>Notifications</h1>
        <p>Request decisions, workplace announcements, birthdays, and published payslips.</p>
      </div>
      <Button
        label="Refresh"
        icon="pi pi-refresh"
        severity="secondary"
        outlined
        :loading="inbox.loading"
        :disabled="inbox.mutating || inbox.loadingMore"
        @click="inbox.load()"
      />
    </div>
    <section class="notification-panel" aria-label="Notification inbox" :aria-busy="inbox.loading">
      <div class="notification-toolbar">
        <div class="notification-filters" role="group" aria-label="Filter notifications">
          <button
            type="button"
            :aria-pressed="inbox.filter === 'all'"
            :class="{ active: inbox.filter === 'all' }"
            :disabled="inbox.mutating"
            @click="inbox.load({ filter: 'all' })"
          >
            All
          </button>
          <button
            type="button"
            :aria-pressed="inbox.filter === 'unread'"
            :class="{ active: inbox.filter === 'unread' }"
            :disabled="inbox.mutating"
            @click="inbox.load({ filter: 'unread' })"
          >
            Unread <span v-if="inbox.unreadCount !== null">{{ inbox.unreadCount }}</span>
          </button>
        </div>
        <Button
          label="Mark all as read"
          icon="pi pi-check-circle"
          text
          size="small"
          :loading="inbox.markingAll"
          :disabled="inbox.mutating || !(inbox.unreadCount > 0)"
          @click="inbox.markAllRead()"
        />
      </div>
      <Message v-if="inbox.error" severity="error" :closable="false" class="notification-error">{{
        inbox.error
      }}</Message>
      <div v-if="inbox.loading && !inbox.items.length" class="notification-skeletons">
        <Skeleton v-for="index in 3" :key="index" height="7rem" />
      </div>
      <div v-else-if="!inbox.items.length && !inbox.error" class="notification-empty" role="status">
        <span class="notification-empty-icon"
          ><i class="pi pi-check-circle" aria-hidden="true"
        /></span>
        <h2>{{ inbox.filter === 'unread' ? 'You’re all caught up' : 'Your inbox is ready' }}</h2>
        <p>
          {{
            inbox.filter === 'unread'
              ? 'You have no unread notifications.'
              : 'HRIS updates will appear here when they arrive.'
          }}
        </p>
      </div>
      <div v-else class="notification-list">
        <article
          v-for="item in inbox.items"
          :key="item.id"
          class="notification-item"
          :class="{ unread: !item.read_at }"
        >
          <span class="notification-icon" aria-hidden="true"
            ><i
              :class="
                item.category === 'announcement'
                  ? 'pi pi-megaphone'
                  : item.category === 'payslip'
                    ? 'pi pi-wallet'
                    : item.category === 'birthday'
                      ? 'pi pi-gift'
                      : 'pi pi-file-edit'
              "
          /></span>
          <div class="notification-content">
            <div class="notification-meta">
              <Tag
                :value="labelFor(item.event || 'update')"
                :severity="statusSeverity(item.event)"
              />
              <time :datetime="item.created_at">{{
                formatValue(item.created_at, 'datetime')
              }}</time>
              <span v-if="!item.read_at" class="unread-label">Unread</span>
            </div>
            <h2>{{ item.title }}</h2>
            <p>{{ item.message }}</p>
            <div class="notification-actions">
              <RouterLink v-if="recordLink(item)" :to="recordLink(item)" class="notification-source"
                >{{ item.category === 'birthday' ? 'Open calendar' : 'Open record' }}
                <i class="pi pi-arrow-right" aria-hidden="true"
              /></RouterLink>
              <span v-if="item.read_at" class="notification-read"
                ><i class="pi pi-check" aria-hidden="true" /> Read</span
              >
              <Button
                v-else
                label="Mark as read"
                text
                size="small"
                :loading="inbox.markingId === item.id"
                :disabled="inbox.mutating"
                @click="inbox.markRead(item)"
              />
            </div>
          </div>
        </article>
      </div>
      <div v-if="inbox.items.length" class="notification-pagination">
        <span
          >{{ inbox.items.length }} of {{ inbox.total }}
          {{ inbox.filter === 'unread' ? 'unread notifications' : 'notifications' }}</span
        >
        <Button
          v-if="inbox.hasMore"
          label="Load more"
          severity="secondary"
          outlined
          size="small"
          :loading="inbox.loadingMore"
          :disabled="inbox.loading || inbox.mutating"
          @click="inbox.load({ append: true })"
        />
      </div>
    </section>
  </div>
</template>

<style scoped>
.notification-panel {
  border: 1px solid var(--line);
  background: var(--panel);
  border-radius: 16px;
  overflow: hidden;
}
.notification-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 22px;
  border-bottom: 1px solid var(--line);
}
.notification-filters {
  display: flex;
  gap: 5px;
  padding: 4px;
  background: var(--soft);
  border-radius: 10px;
}
.notification-filters button {
  display: flex;
  align-items: center;
  gap: 8px;
  background: transparent;
  border: 0;
  border-radius: 7px;
  color: var(--muted);
  font-size: 13px;
  padding: 8px 14px;
}
.notification-filters button.active {
  color: var(--accent);
  background: var(--panel);
  box-shadow: 0 1px 4px #0000000b;
  font-weight: 650;
}
.notification-filters span {
  padding: 1px 6px;
  border-radius: 5px;
  background: var(--accent-soft);
  font-size: 11px;
}
.notification-error {
  margin: 18px 22px;
}
.notification-skeletons {
  display: grid;
  gap: 18px;
  padding: 24px;
}
.notification-empty {
  padding: 64px 22px;
  text-align: center;
}
.notification-empty-icon {
  display: inline-grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 18px;
  background: var(--accent-soft);
  color: var(--accent);
  font-size: 25px;
  margin-bottom: 20px;
}
.notification-empty p {
  color: var(--muted);
  font-size: 14px;
  margin-bottom: 0;
}
.notification-item {
  display: flex;
  gap: 18px;
  padding: 24px;
  border-bottom: 1px solid var(--line);
}
.notification-item:last-child {
  border-bottom: 0;
}
.notification-item.unread {
  background: color-mix(in srgb, var(--accent-soft) 34%, var(--panel));
}
.notification-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  border-radius: 13px;
  background: var(--soft);
  color: var(--muted);
}
.unread .notification-icon {
  background: var(--accent-soft);
  color: var(--accent);
}
.notification-content {
  flex: 1;
  min-width: 0;
}
.notification-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.notification-meta time {
  color: var(--muted);
  font-size: 11px;
}
.unread-label {
  font-size: 10px;
  font-weight: 650;
  color: var(--accent);
}
.notification-content h2 {
  font-size: 15px;
  margin-bottom: 7px;
  overflow-wrap: anywhere;
}
.notification-content p {
  font-size: 13px;
  color: var(--muted);
  margin-bottom: 12px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.notification-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 18px;
  min-height: 32px;
}
.notification-source {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  color: var(--accent);
  font-size: 12px;
  font-weight: 600;
}
.notification-source i {
  font-size: 10px;
}
.notification-read {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--muted);
}
.notification-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  border-top: 1px solid var(--line);
  padding: 18px 24px;
  font-size: 12px;
  color: var(--muted);
}
@media (max-width: 620px) {
  .notification-toolbar {
    flex-wrap: wrap;
    padding: 16px;
    gap: 10px;
  }
  .notification-item {
    padding: 20px 16px;
    gap: 12px;
  }
  .notification-icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
  }
  .notification-pagination {
    padding: 16px;
  }
}
</style>
