<template>
  <!--
    SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
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

        <div class="webapp-sharing">
          <NcCheckboxRadioSwitch
            v-model:checked="webappSharingEnabled"
            type="switch"
          >
            {{ t('integration_jupyterhub', 'Enable OCM webapp sharing (off by default)') }}
          </NcCheckboxRadioSwitch>
          <p class="hint">
            {{ t('integration_jupyterhub', 'Allows users to share folders containing notebooks with remote users as JupyterHub webapps over OCM.') }}
          </p>
        </div>

        <div v-if="webappSharingEnabled" class="webapp-targets">
          <label class="targets-label">{{ t('integration_jupyterhub', 'Offered view targets') }}</label>
          <NcCheckboxRadioSwitch
            v-for="target in allTargets"
            :key="target.value"
            :checked="allowedTargets.includes(target.value)"
            type="checkbox"
            @update:checked="toggleTarget(target.value, $event)"
          >
            {{ target.label }}
          </NcCheckboxRadioSwitch>
          <p class="hint">
            {{ t('integration_jupyterhub', 'Every webapp share offers these targets; the recipient renders whichever it supports. At least one must be selected.') }}
          </p>
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
import { NcButton, NcCheckboxRadioSwitch, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
  name: 'AdminSettings',

  components: {
    Check,
    NcButton,
    NcCheckboxRadioSwitch,
    NcSettingsSection,
    NcTextField,
  },

  data() {
    return {
      jupyterUrl: loadState('integration_jupyterhub', 'jupyter_url', ''),
      webappSharingEnabled: loadState('integration_jupyterhub', 'webapp_sharing_enabled', false),
      allowedTargets: loadState('integration_jupyterhub', 'webapp_allowed_targets', ['iframe', 'redirect', 'blank']),
    }
  },

  computed: {
    allTargets() {
      return [
        { value: 'iframe', label: t('integration_jupyterhub', 'Embed in Nextcloud (iframe)') },
        { value: 'redirect', label: t('integration_jupyterhub', 'Full-page redirect') },
        { value: 'blank', label: t('integration_jupyterhub', 'Open in a new window') },
      ]
    },
  },

  methods: {
    toggleTarget(value, checked) {
      if (checked) {
        if (!this.allowedTargets.includes(value)) {
          this.allowedTargets = [...this.allowedTargets, value]
        }
      } else {
        this.allowedTargets = this.allowedTargets.filter(t => t !== value)
      }
    },
    async save() {
      let url = this.jupyterUrl.trim()
      if (url.endsWith('/')) {
        url = url.slice(0, -1)
        this.jupyterUrl = url
      }
      if (this.webappSharingEnabled && this.allowedTargets.length === 0) {
        showError(t('integration_jupyterhub', 'Select at least one view target.'))
        return
      }
      try {
        await axios.put(generateUrl('/apps/integration_jupyterhub/config'), {
          jupyter_url: url,
          webapp_allowed_targets: this.allowedTargets,
          webapp_sharing_enabled: this.webappSharingEnabled,
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

<style scoped>
.webapp-sharing {
  margin: 1em 0;
}
.webapp-sharing .hint {
  color: var(--color-text-maxcontrast);
  margin-top: 0.25em;
}
.webapp-targets {
  margin: 1em 0;
}
.webapp-targets .targets-label {
  display: block;
  font-weight: 600;
  margin-bottom: 0.25em;
}
.webapp-targets .hint {
  color: var(--color-text-maxcontrast);
  margin-top: 0.25em;
}
</style>
