<template>
	<div class="mc-page">
		<h2>Pessoas</h2>
		<p class="mc-hint">
			Quem na casa toma medicamentos. Serve para saber a quem foi dado o quê — e é por
			pessoa que a pesquisa do histórico faz sentido.
		</p>

		<form class="mc-form" @submit.prevent="create">
			<NcTextField v-model="form.name" label="Nome" placeholder="Maria" required />
			<NcDateTimePickerNative v-model="form.birthDate" label="Data de nascimento" type="date" />
			<NcTextField v-model="form.notes" label="Notas" placeholder="alergias, por exemplo" />
			<NcButton type="primary" native-type="submit">Adicionar</NcButton>
		</form>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!people.length" class="mc-empty">Ainda não há ninguém registado.</p>

		<table v-else class="mc-table">
			<thead><tr><th>Nome</th><th>Nascimento</th><th>Notas</th><th /></tr></thead>
			<tbody>
				<tr v-for="person in people" :key="person.id">
					<td>{{ person.name }}</td>
					<td>{{ formatDate(person.birthDate) }}</td>
					<td>{{ person.notes || '—' }}</td>
					<td class="mc-num"><NcButton type="tertiary" @click="remove(person)">Apagar</NcButton></td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, toIsoDate } from '../utils/format.js'

const loading = ref(true)
const people = ref([])
const form = reactive({ name: '', birthDate: null, notes: '' })

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

const create = async () => {
	if (!form.name.trim()) {
		return
	}
	try {
		await api.createPerson({
			name: form.name.trim(),
			birthDate: toIsoDate(form.birthDate),
			notes: form.notes.trim() || null,
		})
		form.name = ''
		form.birthDate = null
		form.notes = ''
		showSuccess('Pessoa adicionada.')
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível adicionar.')
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
