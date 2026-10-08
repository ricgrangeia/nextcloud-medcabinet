<template>
	<div class="mc-page">
		<h2>Registar por leitura</h2>

		<div class="mc-warn">
			<strong>O que sai de uma leitura é uma proposta, não um registo.</strong>
			O que vem de um código descodificado é exato — tem dígito de controlo. O que vier de
			texto fica marcado para confirmares: um «7» lido como «1» desloca a validade seis anos
			e continua a parecer uma data perfeitamente normal.
		</div>

		<p class="mc-hint">
			Esta app não lê imagens, de propósito. Quem fotografa a caixa e interpreta a fotografia
			é o <strong>agente</strong>, que tem modelo de visão; aqui entra o resultado, e são as
			regras daqui que lhe encontram a validade, o lote e a dosagem. Assim um agente novo ou
			um modelo novo continuam a passar pelas mesmas regras.
		</p>

		<!-- ----------------------------------------------- Código em texto -->

		<h3>Pelo conteúdo do código</h3>
		<p class="mc-hint">
			As caixas de medicamentos sujeitos a receita trazem um <strong>DataMatrix</strong> — o
			quadrado de pontos — com o código do produto, o lote e a <strong>validade</strong>.
			Qualquer app de leitura de códigos do telefone serve: lê o DataMatrix e cola aqui o
			que ela devolver. É o caminho mais fiável que existe, porque o código vem
			descodificado e não lido.
		</p>
		<form class="mc-form" @submit.prevent="readCode">
			<NcTextField v-model="payload" label="Conteúdo do código"
				placeholder="010560123456789717280331..." style="min-width:340px" />
			<NcButton type="primary" native-type="submit" :disabled="!payload.trim() || busy">
				Interpretar
			</NcButton>
		</form>

		<!-- ---------------------------------------------- Texto lido da caixa -->

		<h3>Pelo texto da caixa</h3>
		<p class="mc-hint">
			Escreve ou cola o que está impresso — tudo, como está, sem arrumar. As regras procuram
			a validade, o lote, a dosagem e a quantidade. É também por aqui que o agente entra,
			com o texto que leu da fotografia.
		</p>
		<form class="mc-form mc-form-block" @submit.prevent="readText">
			<textarea v-model="text" class="mc-textarea" rows="6"
				placeholder="BEN-U-RON&#10;paracetamol 500 mg&#10;20 comprimidos&#10;Lote: AB1234&#10;Val.: 03/2028" />
			<div class="mc-form">
				<NcButton type="primary" native-type="submit" :disabled="!text.trim() || busy">
					Ler o texto
				</NcButton>
			</div>
		</form>

		<NcLoadingIcon v-if="busy" :size="32" />

		<!-- ------------------------------------------------------- Proposta -->

		<template v-if="result">
			<h3>Proposta</h3>

			<div v-for="(warning, i) in result.proposal.warnings" :key="i" class="mc-warn">
				{{ warning }}
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
						<td class="mc-hint" style="margin:0">{{ sourceOf(field.key) }}</td>
						<td>
							<span v-if="unverified(field.key)" class="mc-tag mc-tag-expired"
								title="O texto lido não contém este valor.">
								não confirmado
							</span>
							<span v-else-if="needsReview(field.key)" class="mc-tag mc-tag-expiring">
								confirmar
							</span>
							<span v-else-if="result.proposal.fields[field.key]" class="mc-tag mc-tag-ok">
								{{ result.proposal.fields[field.key].confidence === 'code' ? 'do código' : 'confirmado' }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="unverifiedList.length" class="mc-warn mc-bad">
				<strong>Vai ver a caixa antes de gravar.</strong>
				Não foi possível confirmar no texto lido: {{ unverifiedList.map(labelOf).join(', ') }}.
			</div>

			<div v-if="rejectedList.length" class="mc-warn">
				<strong>Campos não aceites</strong> — não entraram na proposta:
				<ul style="margin:4px 0 0 16px">
					<li v-for="entry in rejectedList" :key="entry.field">
						{{ labelOf(entry.field) }}: {{ entry.why }}
					</li>
				</ul>
			</div>

			<div v-if="!form.name && !form.medicineId" class="mc-warn">
				Falta o nome do medicamento. Escreve-o uma vez — havendo código, da próxima a
				mesma caixa preenche-se sozinha.
			</div>

			<details v-if="result.read" class="mc-note">
				<summary>O que as regras encontraram, e onde</summary>
				<table class="mc-table">
					<thead><tr><th>Campo</th><th>Valor</th><th>Texto onde apareceu</th></tr></thead>
					<tbody>
						<tr v-for="(value, key) in result.read.values" :key="key">
							<td>{{ labelOf(key) }}</td>
							<td>{{ value }}</td>
							<td><code>{{ result.read.evidence[key] || '—' }}</code></td>
						</tr>
					</tbody>
				</table>
			</details>

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
import { ref, reactive, computed } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'

const router = useRouter()

const payload = ref('')
const text = ref('')
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

const labelOf = (key) => editableFields.find((f) => f.key === key)?.label ?? key

const needsReview = (key) => result.value?.proposal?.needsReview?.includes(key) ?? false
const unverified = (key) => result.value?.proposal?.unverified?.includes(key) ?? false
const unverifiedList = computed(() => result.value?.proposal?.unverified ?? [])

const rejectedList = computed(() =>
	Object.entries(result.value?.rejected ?? {}).map(([field, why]) => ({ field, why })))

const sourceOf = (key) => result.value?.proposal?.fields?.[key]?.from ?? '—'

const load = (data) => {
	result.value = data
	for (const key of Object.keys(form)) {
		delete form[key]
	}
	Object.assign(form, data.proposal?.values ?? {})
}

const fail = (error, fallback) => {
	showError(error?.response?.data?.ocs?.data?.message ?? fallback)
}

const readCode = async () => {
	busy.value = true
	try {
		load(await api.scanCode(payload.value.trim()))
	} catch (error) {
		fail(error, 'Não foi possível interpretar o código.')
	} finally {
		busy.value = false
	}
}

const readText = async () => {
	busy.value = true
	try {
		load(await api.scanText(text.value, {}, 'texto escrito aqui'))
	} catch (error) {
		fail(error, 'Não foi possível ler o texto.')
	} finally {
		busy.value = false
	}
}

const apply = async () => {
	applying.value = true
	try {
		const saved = await api.scanApply({ ...form, source: 'datamatrix' })
		showSuccess('Guardado no armário.')
		reset()
		router.push({ name: 'medicine', params: { id: saved.medicineId } })
	} catch (error) {
		fail(error, 'Não foi possível guardar.')
	} finally {
		applying.value = false
	}
}

const reset = () => {
	result.value = null
	payload.value = ''
	text.value = ''
}
</script>
