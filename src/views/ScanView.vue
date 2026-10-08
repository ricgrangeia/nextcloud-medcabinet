<template>
	<div class="mc-page">
		<h2>Registar por leitura</h2>
		<p class="mc-hint">
			As caixas de medicamentos sujeitos a receita trazem um <strong>DataMatrix</strong> — o
			quadrado de pontos — com o código do produto, o lote e a <strong>validade</strong>.
			São precisamente os dados chatos e arriscados de escrever à mão. O nome não vem lá,
			mas esse lê-se na caixa num segundo.
		</p>

		<div class="mc-warn">
			<strong>O que sai de uma leitura é uma proposta, não um registo.</strong>
			O que vem do código é exato — tem dígito de controlo. O que vier de texto numa
			fotografia fica marcado para confirmares: um «7» lido como «1» desloca a validade seis
			anos e continua a parecer uma data perfeitamente normal.
		</div>

		<h3>Pelo conteúdo do código</h3>
		<p class="mc-hint">
			Qualquer app de leitura de códigos do telefone serve. Lê o DataMatrix e cola aqui o
			que ela devolver.
		</p>
		<form class="mc-form" @submit.prevent="readCode">
			<NcTextField v-model="payload" label="Conteúdo do código"
				placeholder="010560123456789717280331..." style="min-width:340px" />
			<NcButton type="primary" native-type="submit" :disabled="!payload.trim() || busy">
				Interpretar
			</NcButton>
		</form>

		<h3>Por fotografia</h3>
		<template v-if="status && status.available">
			<p class="mc-hint">
				Podes enviar várias fotos da mesma caixa — a frente para o nome, o painel do
				DataMatrix para a validade. O que cada uma disser junta-se, e os desacordos
				aparecem.
			</p>
			<form class="mc-form" @submit.prevent="readPhotos">
				<input ref="fileInput" type="file" accept="image/*" multiple capture="environment"
					@change="pick">
				<NcButton type="primary" native-type="submit" :disabled="!files.length || busy">
					{{ files.length > 1 ? `Ler ${files.length} fotos` : 'Ler foto' }}
				</NcButton>
			</form>
		</template>
		<div v-else-if="status" class="mc-warn">
			A leitura por fotografia não está disponível. {{ status.reason }}
		</div>

		<NcLoadingIcon v-if="busy" :size="32" />

		<template v-if="result">
			<h3>Proposta</h3>

			<div v-for="(warning, i) in result.proposal.warnings" :key="i" class="mc-warn">
				{{ warning }}
			</div>

			<div v-for="(entry, i) in result.failed || []" :key="'f' + i" class="mc-warn">
				<strong>{{ entry.filename }}</strong> — {{ entry.reason }}
			</div>

            <p v-if="result.medicine" class="mc-hint">
				Este código já estava associado a
				<strong>{{ result.medicine.name }}{{ result.medicine.strength ? ' ' + result.medicine.strength : '' }}</strong>.
				A caixa nova vai para esse medicamento.
			</p>

			<table class="mc-table">
				<thead><tr><th>Campo</th><th>Valor</th><th>De onde veio</th><th /></tr></thead>
				<tbody>
					<tr v-for="field in editableFields" :key="field.key">
						<td>{{ field.label }}</td>
						<td>
							<NcTextField :model-value="String(form[field.key] ?? '')"
								:label="field.label" :placeholder="field.placeholder || ''"
								@update:model-value="(v) => (form[field.key] = v)" />
						</td>
						<td class="mc-hint" style="margin:0">
							{{ sourceOf(field.key) }}
						</td>
						<td>
							<span v-if="needsReview(field.key)" class="mc-tag mc-tag-expiring">
								confirmar
							</span>
							<span v-else-if="result.proposal.fields[field.key]" class="mc-tag mc-tag-ok">
								do código
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="!form.name && !form.medicineId" class="mc-warn">
                O código identifica o produto mas não lhe dá nome. Escreve-o uma vez — da próxima,
				a mesma caixa preenche-se sozinha.
			</div>

			<div class="mc-form" style="margin-top:16px">
				<NcButton type="primary" :disabled="applying || (!form.name && !form.medicineId)"
					@click="apply">
					Guardar no armário
				</NcButton>
				<NcButton type="tertiary" @click="reset">Descartar</NcButton>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'

const router = useRouter()

const status = ref(null)
const payload = ref('')
const files = ref([])
const fileInput = ref(null)
const result = ref(null)
const busy = ref(false)
const applying = ref(false)

const form = reactive({})

const editableFields = [
	{ key: 'name', label: 'Nome na caixa', placeholder: 'Brufen' },
	{ key: 'substance', label: 'Substância ativa', placeholder: 'ibuprofeno' },
	{ key: 'strength', label: 'Dosagem', placeholder: '600 mg' },
	{ key: 'form', label: 'Forma', placeholder: 'comprimido' },
	{ key: 'unit', label: 'Conta-se em', placeholder: 'comprimido' },
	{ key: 'expiry', label: 'Validade', placeholder: 'AAAA-MM-DD' },
	{ key: 'batch', label: 'Lote' },
	{ key: 'unitsTotal', label: 'Quantas unidades', placeholder: '20' },
	{ key: 'location', label: 'Onde fica', placeholder: 'gaveta da cozinha' },
	{ key: 'gtin', label: 'Código do produto' },
]

const needsReview = (key) => result.value?.proposal.needsReview.includes(key) ?? false

const sourceOf = (key) => {
	const entry = result.value?.proposal.fields[key]
	return entry ? entry.from : '—'
}

const pick = (event) => {
	files.value = Array.from(event.target.files ?? [])
}

const load = (data) => {
	result.value = data
	for (const key of Object.keys(form)) {
		delete form[key]
	}
	Object.assign(form, data.proposal?.values ?? {})
}

const readCode = async () => {
	busy.value = true
	try {
		load(await api.scanCode(payload.value.trim()))
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível interpretar o código.')
	} finally {
		busy.value = false
	}
}

const readPhotos = async () => {
	busy.value = true
	try {
		const data = await api.scanPhotos(files.value)
		if (!data.proposal) {
			result.value = { proposal: { warnings: [], fields: {}, needsReview: [], values: {} }, failed: data.failed }
			showError('Não se encontrou nenhum código nas fotos.')
			return
		}
		load(data)
	} catch (error) {
		const body = error?.response?.data?.ocs?.data
		if (body?.failed?.length) {
			result.value = { proposal: { warnings: [], fields: {}, needsReview: [], values: {} }, failed: body.failed }
		}
		showError(body?.message ?? 'Não foi possível ler as fotos.')
	} finally {
		busy.value = false
	}
}

const apply = async () => {
	applying.value = true
	try {
		const values = { ...form, source: 'datamatrix' }
		const saved = await api.scanApply(values)
		showSuccess('Guardado no armário.')
		reset()
		router.push({ name: 'medicine', params: { id: saved.medicineId } })
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível guardar.')
	} finally {
		applying.value = false
	}
}

const reset = () => {
	result.value = null
	payload.value = ''
	files.value = []
	if (fileInput.value) {
		fileInput.value.value = ''
	}
}

onMounted(async () => {
	try {
		status.value = await api.scanStatus()
	} catch (error) {
		status.value = { available: false, reason: 'Não foi possível saber o estado do leitor.' }
	}
})
</script>
