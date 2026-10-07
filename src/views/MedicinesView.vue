<template>
	<div class="mc-page">
		<h2>Medicamentos</h2>
		<p class="mc-hint">
			Preenche sempre a <strong>substância ativa</strong>. É o que faz "Brufen" e
			"ibuprofeno" encontrarem-se um ao outro na pesquisa — sem ela, só encontras o que
			souberes escrever exatamente como está na caixa.
		</p>

		<form class="mc-form" @submit.prevent="create">
			<NcTextField v-model="form.name" label="Nome na caixa" placeholder="Brufen" required />
			<NcTextField v-model="form.substance" label="Substância ativa" placeholder="ibuprofeno" />
			<NcTextField v-model="form.strength" label="Dosagem" placeholder="600 mg" />
			<NcSelect v-model="form.form" :options="FORMS" input-label="Forma" :clearable="true" />
			<NcSelect v-model="form.unit" :options="UNITS" input-label="Conta-se em" :clearable="false" />
			<NcTextField v-if="needsOpeningDays" v-model="form.daysAfterOpening" type="number"
				label="Dias após abertura" placeholder="28" />
			<NcButton type="primary" native-type="submit">Criar</NcButton>
		</form>

		<div v-if="needsOpeningDays && !form.daysAfterOpening" class="mc-warn">
			<strong>{{ form.form }}</strong> perde validade depois de aberto. Sem os dias após
			abertura, a app não consegue dizer se está bom — e mostrar a data da caixa seria
			dizer que está bom o que pode já não estar. Vem no folheto.
		</div>

		<div class="mc-form">
			<NcTextField v-model="query" label="Procurar" placeholder="nome ou substância"
				@update:model-value="search" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!medicines.length" class="mc-empty">
			{{ query ? 'Nada encontrado.' : 'Ainda não há medicamentos.' }}
		</p>

		<table v-else class="mc-table">
			<thead>
				<tr>
					<th>Nome</th><th>Substância</th><th>Forma</th>
					<th class="mc-num">Resta</th><th>Estado</th><th class="mc-num">Caixas</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="med in medicines" :key="med.id">
					<td>
						<RouterLink :to="{ name: 'medicine', params: { id: med.id } }">
							{{ med.name }}{{ med.strength ? ' ' + med.strength : '' }}
						</RouterLink>
					</td>
					<td>{{ med.substance || '—' }}</td>
					<td>{{ med.form || '—' }}</td>
					<td class="mc-num">
						{{ med.unitsUsable === null ? '—' : `${formatNumber(med.unitsUsable)} ${med.unit}` }}
					</td>
					<td><span :class="['mc-tag', 'mc-tag-' + med.worstStatus]">{{ STATUS_LABEL[med.worstStatus] }}</span></td>
					<td class="mc-num">{{ med.packages.length }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatNumber, STATUS_LABEL, FORMS, UNITS, PERISHABLE_FORMS } from '../utils/format.js'

const loading = ref(true)
const medicines = ref([])
const query = ref('')
let searchTimer = null

const form = reactive({
	name: '', substance: '', strength: '', form: null,
	unit: 'comprimido', daysAfterOpening: '',
})

const needsOpeningDays = computed(() => PERISHABLE_FORMS.includes(form.form))

const load = async () => {
	loading.value = true
	try {
		medicines.value = await api.listMedicines(query.value || undefined)
	} catch (error) {
		showError('Não foi possível carregar os medicamentos.')
	} finally {
		loading.value = false
	}
}

const search = () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(load, 300)
}

const create = async () => {
	if (!form.name.trim()) {
		return
	}
	try {
		await api.createMedicine({
			name: form.name.trim(),
			substance: form.substance.trim() || null,
			strength: form.strength.trim() || null,
			form: form.form,
			unit: form.unit,
			daysAfterOpening: form.daysAfterOpening === '' ? null : Number(form.daysAfterOpening),
		})
		form.name = ''
		form.substance = ''
		form.strength = ''
		form.daysAfterOpening = ''
		showSuccess('Medicamento criado. Agora registra as caixas que tens.')
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível criar.')
	}
}

onMounted(load)
</script>
