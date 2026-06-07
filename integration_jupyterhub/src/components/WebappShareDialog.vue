<template>
  <!--
    SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
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
        {{ t('integration_jupyterhub', 'Share folder {name} via OCM to a JupyterHub-capable peer. Both file access and webapp launch are sent in one multi-protocol share.', { name }) }}
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
        <label>{{ t('integration_jupyterhub', 'Permissions') }}</label>
        <div class="perms">
          <NcCheckboxRadioSwitch v-model="permissions.read" :disabled="true" type="checkbox">
            {{ t('integration_jupyterhub', 'Read') }}
          </NcCheckboxRadioSwitch>
          <NcCheckboxRadioSwitch v-model="permissions.write" type="checkbox">
            {{ t('integration_jupyterhub', 'Write — let the recipient save changes back (two-way sync)') }}
          </NcCheckboxRadioSwitch>
        </div>
        <p class="hint">
          {{ t('integration_jupyterhub', 'Read is always granted. The view target is set by the instance administrator and negotiated with the recipient.') }}
        </p>
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
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
  name: 'WebappShareDialog',

  components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },

  props: {
    path: { type: String, required: true },
    name: { type: String, required: true },
  },

  emits: ['close'],

  data() {
    return {
      open: true,
      shareWith: '',
      permissions: { read: true, write: false },
      sending: false,
      errorMessage: '',
    }
  },

  computed: {
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
      const permissions = ['read']
      if (this.permissions.write) {
        permissions.push('write')
      }
      try {
        const response = await axios.post(
          generateUrl('/apps/integration_jupyterhub/api/v1/webapp-share'),
          {
            path: this.path,
            shareWith: this.shareWith.trim(),
            permissions,
          },
        )
        const grantedPermissions = Array.isArray(response.data?.permissions)
          ? response.data.permissions
          : permissions
        showSuccess(t(
          'integration_jupyterhub',
          'Shared {name} with {peer} ({permissions})',
          {
            name: this.name,
            peer: this.shareWith.trim(),
            permissions: grantedPermissions.join(', '),
          },
        ))
        this.onClose()
      } catch (e) {
        const detail = e?.response?.data?.error ?? e.message
        this.errorMessage = detail
        showError(t('integration_jupyterhub', 'Could not share: {detail}', { detail }))
        this.sending = false
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
.webapp-share-dialog .perms {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.webapp-share-dialog .error {
  color: var(--color-error);
  margin-top: 8px;
}
.webapp-share-dialog .hint {
  color: var(--color-text-maxcontrast);
  font-size: 0.85em;
  margin-top: 4px;
}
</style>
