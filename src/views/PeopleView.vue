<template>
	<div class="mc-page">
		<div class="mc-head">
			<h2>Pessoas</h2>
			<NcButton type="button" variant="primary" @click="startCreate">
				<template #icon>
					<AccountPlusIcon :size="20" />
				</template>
				Adicionar pessoa
			</NcButton>
		</div>

		<p class="mc-hint">
			Quem na casa toma medicamentos. Serve para saber a quem foi dado o quê — e é por
			pessoa que a pesquisa do histórico faz sentido.
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!people.length" class="mc-empty">Ainda não há ninguém registado.</p>

		<table v-else class="mc-table">
			<thead><tr><th>Nome</th><th>Nascimento</th><th>Notas</th><th /></tr></thead>
			<tbody>
				<tr v-for="person in people" :key="person.id">
					<td>{{ person.name }}</td>
					<td>{{ formatDate(person.birthDate) }}</td>
					<td>{{ person.notes || '—' }}</td>
					<td class="mc-num">
						<NcButton type="button" variant="tertiary" @click="remove(person)">Apagar</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<FormDialog v-model:open="creating" name="Adicionar pessoa" submit-label="Adicionar"
			:busy="saving" :disabled="!form.name.trim()" @submit="create">
			<NcTextField v-model="form.name" label="Nome" placeholder="Maria" required />
			<NcDateTimePickerNative v-model="form.birthDate" label="Data de nascimento" type="date" />
			<NcTextField v-model="form.notes" label="Notas" placeholder="alergias, por exemplo" />
		</FormDialog>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import FormDialog from '../components/FormDialog.vue'
import api from '../api/client.js'
import { formatDate, toIsoDate } from '../utils/format.js'

const loading = ref(true)
const people = ref([])
const creating = ref(false)
const saving = ref(false)

const BLANK = { name: '', birthDate: null, notes: '' }
const form = reactive({ ...BLANK })

const load = async () => {
	loading.value = true
	try {
		people.value = await api.listPeople()
	} catch (error) {
		showError('Não foi possível carregar as pessoas.')
	} finally {
		loading.value = false
	}
}

const startCreate = () => {
	Object.assign(form, BLANK)
	creating.value = true
}

const create = async () => {
	if (!form.name.trim()) {
		return
	}
	saving.value = true
	try {
		await api.createPerson({
			name: form.name.trim(),
			birthDate: toIsoDate(form.birthDate),
			notes: form.notes.trim() || null,
		})
		creating.value = false
		showSuccess('Pessoa adicionada.')
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível adicionar.')
	} finally {
		saving.value = false
	}
}

const remove = async (person) => {
	if (!window.confirm(`Apagar ${person.name}? Os episódios dela ficam sem pessoa associada.`)) {
		return
	}
	try {
		await api.deletePerson(person.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar.')
	}
}

onMounted(load)
</script>
