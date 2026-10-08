<template>
	<div class="mc-page">
		<div class="mc-head">
			<h2>Para que serviram</h2>
			<NcButton type="button" variant="primary" @click="startCreate">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				Registar episódio
			</NcButton>
		</div>

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
							<NcButton type="button" variant="tertiary" @click="removeItem(item)">Remover</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="mc-hint" style="margin:0 0 8px">Sem medicamentos associados.</p>

			<p v-if="ep.outcome" class="mc-hint" style="margin:8px 0 0">
				<strong>Resultado:</strong> {{ ep.outcome }}
			</p>

			<div class="mc-form" style="margin:12px 0 0">
				<NcButton type="button" @click="startItem(ep)">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					Acrescentar medicamento
				</NcButton>
				<NcButton type="button" variant="tertiary" @click="remove(ep)">Apagar episódio</NcButton>
			</div>
		</div>

		<!-- --------------------------------------------- Registar episodio -->

		<FormDialog v-model:open="creating" name="Registar episódio"
			submit-label="Registar" :busy="saving" :disabled="!form.reason.trim()"
			@submit="create">
			<NcTextField v-model="form.reason" label="Motivo" placeholder="otite" required />
			<NcSelect v-model="form.personId" :options="peopleOptions" :reduce="(o) => o.value"
				label="label" input-label="Para quem" />
			<NcDateTimePickerNative v-model="form.startedAt" label="Começou em" type="date" />
			<NcTextField v-model="form.prescriber" label="Indicado por" placeholder="Dr. X, ou 'nós'" />
			<p class="mc-hint" style="margin:0">
				Os medicamentos acrescentam-se depois, ao episódio já criado.
			</p>
		</FormDialog>

		<!-- ------------------------------------- Acrescentar ao episodio -->

		<FormDialog v-model:open="addingItem"
			:name="`Acrescentar a «${itemTarget?.reason ?? ''}»`"
			submit-label="Acrescentar" :busy="savingItem" :disabled="!itemForm.medicineId"
			@submit="addItem">
			<NcSelect v-model="itemForm.medicineId" :options="medicineOptions"
				:reduce="(o) => o.value" label="label" input-label="Medicamento" />
			<NcTextField v-model="itemForm.posology" label="Posologia"
				placeholder="1 comp. de 8 em 8 h, 8 dias" />
			<p class="mc-hint" style="margin:0">
				A posologia fica como veio escrita. Arrumá-la em campos seria inventar uma
				precisão que a receita não tem.
			</p>
		</FormDialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import FormDialog from '../components/FormDialog.vue'
import api from '../api/client.js'
import { formatDate, toIsoDate } from '../utils/format.js'

const loading = ref(true)
const episodes = ref([])
const people = ref([])
const medicines = ref([])
const query = ref('')
const filterPerson = ref(null)
let searchTimer = null

const creating = ref(false)
const saving = ref(false)
const form = reactive({ reason: '', personId: null, startedAt: new Date(), prescriber: '' })

// Uma janela so, com o episodio a que se esta a acrescentar. Antes havia um
// formulario por cartao e um estado por episodio; o que se ganha e nao ter
// tantos formularios abertos quantos os episodios da lista.
const addingItem = ref(false)
const savingItem = ref(false)
const itemTarget = ref(null)
const itemForm = reactive({ medicineId: null, posology: '' })

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

const startCreate = () => {
	Object.assign(form, { reason: '', personId: null, startedAt: new Date(), prescriber: '' })
	creating.value = true
}

const create = async () => {
	if (!form.reason.trim()) {
		return
	}
	saving.value = true
	try {
		await api.createEpisode({
			reason: form.reason.trim(),
			personId: form.personId,
			startedAt: toIsoDate(form.startedAt),
			prescriber: form.prescriber.trim() || null,
		})
		creating.value = false
		showSuccess('Episódio registado. Acrescenta-lhe os medicamentos.')
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível registar.')
	} finally {
		saving.value = false
	}
}

const startItem = (ep) => {
	itemTarget.value = ep
	Object.assign(itemForm, { medicineId: null, posology: '' })
	addingItem.value = true
}

const addItem = async () => {
	if (!itemTarget.value || !itemForm.medicineId) {
		return
	}
	savingItem.value = true
	try {
		await api.addEpisodeItem(itemTarget.value.id, {
			medicineId: itemForm.medicineId,
			posology: itemForm.posology.trim() || null,
		})
		addingItem.value = false
		await load()
	} catch (error) {
		showError('Não foi possível acrescentar.')
	} finally {
		savingItem.value = false
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
