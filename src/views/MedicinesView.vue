<template>
	<div class="mc-page">
		<div class="mc-head">
			<h2>Medicamentos</h2>
			<NcButton type="button" variant="primary" @click="startCreate">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				Novo medicamento
			</NcButton>
		</div>

		<p class="mc-hint">
			O que há em casa, com o que resta de cada um. Clica num nome para ver as caixas,
			as validades e para que já serviu.
		</p>

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

		<!-- ------------------------------------------------------- Criar -->

		<FormDialog v-model:open="creating" name="Novo medicamento" submit-label="Criar"
			:busy="saving" :disabled="!form.name.trim()" @submit="create">
			<p class="mc-hint" style="margin:0">
				Preenche sempre a <strong>substância ativa</strong>. É o que faz "Brufen" e
				"ibuprofeno" encontrarem-se um ao outro na pesquisa — sem ela, só encontras o que
				souberes escrever exatamente como está na caixa.
			</p>

			<NcTextField v-model="form.name" label="Nome na caixa" placeholder="Brufen" required />
			<NcTextField v-model="form.substance" label="Substância ativa" placeholder="ibuprofeno" />
			<NcTextField v-model="form.strength" label="Dosagem" placeholder="600 mg" />
			<NcSelect v-model="form.form" :options="FORMS" input-label="Forma" :clearable="true" />
			<NcSelect v-model="form.unit" :options="UNITS" input-label="Conta-se em" :clearable="false" />
			<NcTextField v-if="needsOpeningDays" v-model="form.daysAfterOpening" type="number"
				label="Dias após abertura" placeholder="28" />

			<div v-if="needsOpeningDays && !form.daysAfterOpening" class="mc-warn" style="margin:0">
				<strong>{{ form.form }}</strong> perde validade depois de aberto. Sem os dias após
				abertura, a app não consegue dizer se está bom — e mostrar a data da caixa seria
				dizer que está bom o que pode já não estar. Vem no folheto.
			</div>
		</FormDialog>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import FormDialog from '../components/FormDialog.vue'
import api from '../api/client.js'
import { formatNumber, isPerishableForm, STATUS_LABEL, FORMS, UNITS } from '../utils/format.js'

const loading = ref(true)
const medicines = ref([])
const query = ref('')
const creating = ref(false)
const saving = ref(false)
let searchTimer = null

const BLANK = {
	name: '', substance: '', strength: '', form: null,
	unit: 'comprimido', daysAfterOpening: '',
}

const form = reactive({ ...BLANK })

const needsOpeningDays = computed(() => isPerishableForm(form.form))

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
		await api.createMedicine({
			name: form.name.trim(),
			substance: form.substance.trim() || null,
			strength: form.strength.trim() || null,
			form: form.form,
			unit: form.unit,
			daysAfterOpening: form.daysAfterOpening === '' ? null : Number(form.daysAfterOpening),
		})
		creating.value = false
		showSuccess('Medicamento criado. Agora registra as caixas que tens.')
		await load()
	} catch (error) {
		// A janela fica aberta de proposito: o que foi escrito nao se perde.
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível criar.')
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>
