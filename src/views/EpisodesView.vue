<template>
	<div class="mc-page">
		<h2>Para que serviram</h2>
		<p class="mc-hint">
			Um episódio é <strong>um motivo</strong>, para <strong>uma pessoa</strong>, num
			intervalo. É aqui que fica o "para quê" — e não no medicamento, porque o mesmo
			medicamento serve coisas diferentes em épocas diferentes.
		</p>

		<div class="mc-warn">
			Isto é um <strong>registo</strong>, não um conselho. Diz o que foi usado, quando e por
			indicação de quem — que é o que te serve para falar com um médico, não para dispensar
			de falar com ele.
		</div>

		<form class="mc-form" @submit.prevent="create">
			<NcTextField v-model="form.reason" label="Motivo" placeholder="otite" required />
			<NcSelect v-model="form.personId" :options="peopleOptions" :reduce="(o) => o.value"
				label="label" input-label="Para quem" />
			<NcDateTimePickerNative v-model="form.startedAt" label="Começou em" type="date" />
			<NcTextField v-model="form.prescriber" label="Indicado por" placeholder="Dr. X, ou 'nós'" />
			<NcButton type="primary" native-type="submit">Registar episódio</NcButton>
		</form>

		<div class="mc-form">
			<NcTextField v-model="query" label="Procurar" placeholder="motivo, resultado ou notas"
				@update:model-value="search" />
			<NcSelect v-model="filterPerson" :options="peopleOptions" :reduce="(o) => o.value"
				label="label" input-label="Pessoa" @update:model-value="load" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!episodes.length" class="mc-empty">
			{{ query ? 'Nada encontrado.' : 'Ainda não há episódios registados.' }}
		</p>

		<div v-for="ep in episodes" :key="ep.id" class="mc-card">
			<div class="mc-card-head">
				<strong>{{ ep.reason }}</strong>
				<span class="mc-hint" style="margin:0">
					{{ ep.personName || 'sem pessoa' }} · {{ formatDate(ep.startedAt) }}
					<template v-if="ep.endedAt"> → {{ formatDate(ep.endedAt) }}</template>
					<template v-if="ep.prescriber"> · {{ ep.prescriber }}</template>
				</span>
			</div>

			<table v-if="ep.items.length" class="mc-table">
				<thead><tr><th>Medicamento</th><th>Posologia</th><th /></tr></thead>
				<tbody>
					<tr v-for="item in ep.items" :key="item.id">
						<td>{{ item.medicineName || '—' }}</td>
						<td>{{ item.posology || '—' }}</td>
						<td class="mc-num">
							<NcButton type="tertiary" @click="removeItem(item)">Remover</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="mc-hint" style="margin:0 0 8px">Sem medicamentos associados.</p>

			<p v-if="ep.outcome" class="mc-hint" style="margin:8px 0 0">
				<strong>Resultado:</strong> {{ ep.outcome }}
			</p>

			<form class="mc-form" style="margin-top:12px" @submit.prevent="addItem(ep)">
				<NcSelect v-model="itemForm[ep.id].medicineId" :options="medicineOptions"
					:reduce="(o) => o.value" label="label" input-label="Medicamento" />
				<NcTextField v-model="itemForm[ep.id].posology" label="Posologia"
					placeholder="1 comp. de 8 em 8 h, 8 dias" />
				<NcButton native-type="submit">Acrescentar</NcButton>
				<NcButton type="tertiary" @click="remove(ep)">Apagar episódio</NcButton>
			</form>
		</div>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, toIsoDate } from '../utils/format.js'

const loading = ref(true)
const episodes = ref([])
const people = ref([])
const medicines = ref([])
const query = ref('')
const filterPerson = ref(null)
const itemForm = reactive({})
let searchTimer = null

const form = reactive({ reason: '', personId: null, startedAt: new Date(), prescriber: '' })

const peopleOptions = computed(() => people.value.map((p) => ({ value: p.id, label: p.name })))
const medicineOptions = computed(() => medicines.value.map((m) => ({
	value: m.id,
	label: m.name + (m.strength ? ' ' + m.strength : ''),
})))

const load = async () => {
	loading.value = true
	try {
		const [eps, ppl, meds] = await Promise.all([
			api.listEpisodes({ q: query.value || undefined, personId: filterPerson.value ?? undefined }),
			api.listPeople(),
			api.listMedicines(),
		])
		episodes.value = eps
		people.value = ppl
		medicines.value = meds
		for (const ep of eps) {
			if (!itemForm[ep.id]) {
				itemForm[ep.id] = { medicineId: null, posology: '' }
			}
		}
	} catch (error) {
		showError('Não foi possível carregar os episódios.')
	} finally {
		loading.value = false
	}
}

const search = () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(load, 300)
}

const create = async () => {
	if (!form.reason.trim()) {
		return
	}
	try {
		await api.createEpisode({
			reason: form.reason.trim(),
			personId: form.personId,
			startedAt: toIsoDate(form.startedAt),
			prescriber: form.prescriber.trim() || null,
		})
		form.reason = ''
		form.prescriber = ''
		showSuccess('Episódio registado. Acrescenta-lhe os medicamentos.')
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível registar.')
	}
}

const addItem = async (ep) => {
	const entry = itemForm[ep.id]
	if (!entry?.medicineId) {
		return
	}
	try {
		await api.addEpisodeItem(ep.id, {
			medicineId: entry.medicineId,
			posology: entry.posology.trim() || null,
		})
		entry.posology = ''
		await load()
	} catch (error) {
		showError('Não foi possível acrescentar.')
	}
}

const removeItem = async (item) => {
	try {
		await api.deleteEpisodeItem(item.id)
		await load()
	} catch (error) {
		showError('Não foi possível remover.')
	}
}

const remove = async (ep) => {
	if (!window.confirm(`Apagar o episódio "${ep.reason}"?`)) {
		return
	}
	try {
		await api.deleteEpisode(ep.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar.')
	}
}

onMounted(load)
</script>
