<template>
  <!--
    SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
    SPDX-License-Identifier: AGPL-3.0-or-later
    -->
  <div id="jupyterhub_prefs" class="section">
    <NcSettingsSection
      name="JupyterHub"
      description="JupyterHub integration for Nextcloud."
      doc-url="https://jupyter.org/hub"
    >
      <p>
        {{ t('integration_jupyterhub', 'Specify the URL where Nextcloud can reach your JupyterHub instance, e.g. https://jupyter.example.com') }}
      </p>
      <form @submit.prevent="save">
        <div class="external-label">
          <label for="jupyter_url">{{ t('integration_jupyterhub', 'JupyterHub URL') }}</label>
          <NcTextField
            id="jupyter_url"
            v-model="jupyterUrl"
            :label-outside="true"
            :placeholder="t('integration_jupyterhub', 'https://jupyter.example.com')"
          />
        </div>
        <NcButton
          :wide="true"
          @click="save"
        >
          <template #icon>
            <Check :size="20" />
          </template>
          {{ t('integration_jupyterhub', 'Save') }}
        </NcButton>
      </form>
    </NcSettingsSection>
  </div>
</template>

<script>
import Check from 'vue-material-design-icons/Check.vue'
import { NcButton, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
  name: 'AdminSettings',

  components: {
    Check,
    NcButton,
    NcSettingsSection,
    NcTextField,
  },

  data() {
    return {
      jupyterUrl: loadState('integration_jupyterhub', 'jupyter_url', ''),
    }
  },

  methods: {
    async save() {
      let url = this.jupyterUrl.trim()
      if (url.endsWith('/')) {
        url = url.slice(0, -1)
        this.jupyterUrl = url
      }
      try {
        await axios.put(generateUrl('/apps/integration_jupyterhub/config'), {
          jupyter_url: url,
        })
        showSuccess(t('integration_jupyterhub', 'JupyterHub settings saved.'))
      } catch (e) {
        console.error(e)
        showError(t('integration_jupyterhub', 'Failed to save JupyterHub settings.'))
      }
    },
  },
}
</script>
