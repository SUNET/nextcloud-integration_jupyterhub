<template>
  <!--
    SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
    SPDX-License-Identifier: AGPL-3.0-or-later
    -->
  <NcDialog
    :name="t('integration_jupyterhub', 'Share notebook folder as webapp')"
    :open="open"
    size="normal"
    @update:open="onClose"
  >
    <div class="webapp-share-dialog">
      <p>
        {{ t('integration_jupyterhub', 'Share the folder {name} via OCM to a JupyterHub-capable peer.', { name }) }}
      </p>

      <div class="field">
        <label for="ocm-share-with">{{ t('integration_jupyterhub', 'Recipient (user@host)') }}</label>
        <NcTextField
          id="ocm-share-with"
          v-model="shareWith"
          :placeholder="'alice@cloud.example.com'"
          :label-outside="true"
        />
      </div>

      <div class="field">
        <label>{{ t('integration_jupyterhub', 'How should the recipient open it?') }}</label>
        <div class="modes">
          <label v-for="mode in modes" :key="mode.value" class="mode">
            <input
              type="radio"
              :value="mode.value"
              v-model="viewMode"
            >
            <span>{{ mode.label }}</span>
          </label>
        </div>
      </div>

      <p v-if="errorMessage" class="error">
        {{ errorMessage }}
      </p>
    </div>

    <template #actions>
      <NcButton :disabled="sending" @click="onClose">
        {{ t('integration_jupyterhub', 'Cancel') }}
      </NcButton>
      <NcButton type="primary" :disabled="!canSubmit" @click="submit">
        {{ sending ? t('integration_jupyterhub', 'Sharing…') : t('integration_jupyterhub', 'Share') }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
  name: 'WebappShareDialog',

  components: { NcButton, NcDialog, NcTextField },

  props: {
    path: { type: String, required: true },
    name: { type: String, required: true },
  },

  emits: ['close', 'error'],

  data() {
    return {
      open: true,
      shareWith: '',
      viewMode: 'iframe',
      sending: false,
      errorMessage: '',
    }
  },

  computed: {
    modes() {
      return [
        { value: 'iframe', label: t('integration_jupyterhub', 'Embed in Nextcloud (iframe)') },
        { value: 'redirect', label: t('integration_jupyterhub', 'Full-page redirect') },
        { value: 'new-window', label: t('integration_jupyterhub', 'Open in a new window') },
      ]
    },
    canSubmit() {
      return !this.sending && /.+@.+/.test(this.shareWith.trim())
    },
  },

  methods: {
    onClose() {
      this.open = false
      this.$emit('close')
    },
    async submit() {
      this.sending = true
      this.errorMessage = ''
      try {
        const response = await axios.post(
          generateUrl('/apps/integration_jupyterhub/api/v1/webapp-share'),
          {
            path: this.path,
            shareWith: this.shareWith.trim(),
            viewMode: this.viewMode,
          },
        )
        showSuccess(t(
          'integration_jupyterhub',
          'Shared {name} with {peer} ({mode})',
          {
            name: this.name,
            peer: this.shareWith.trim(),
            mode: response.data?.viewMode ?? this.viewMode,
          },
        ))
        this.onClose()
      } catch (e) {
        const detail = e?.response?.data?.error ?? e.message
        this.errorMessage = detail
        showError(t('integration_jupyterhub', 'Could not share: {detail}', { detail }))
        this.sending = false
        this.$emit('error', e)
      }
    },
  },
}
</script>

<style scoped>
.webapp-share-dialog .field {
  margin-block: 12px;
}
.webapp-share-dialog .field label {
  display: block;
  font-weight: 600;
  margin-bottom: 4px;
}
.webapp-share-dialog .modes {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.webapp-share-dialog .modes .mode {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: normal;
}
.webapp-share-dialog .error {
  color: var(--color-error);
  margin-top: 8px;
}
</style>
