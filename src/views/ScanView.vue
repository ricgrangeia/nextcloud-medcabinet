<template>
	<div class="mc-page">
		<h2>Registar por leitura</h2>

		<div class="mc-warn">
			<strong>O que sai de uma leitura é uma proposta, não um registo.</strong>
			O que vem de um código descodificado é exato — tem dígito de controlo. O que vier de
			texto numa fotografia fica marcado para confirmares: um «7» lido como «1» desloca a
			validade seis anos e continua a parecer uma data perfeitamente normal.
		</div>

		<!-- ------------------------------------------------ IA do servidor -->

		<h3>Com a IA deste servidor</h3>
		<template v-if="ai && ai.available">
			<p class="mc-hint">
				Fotografa a caixa — a frente para o nome, e o painel onde está a validade. Envia
				as que quiseres: são todas da mesma caixa, e o que cada uma disser junta-se.
			</p>
			<p class="mc-hint">
				A IA faz duas coisas diferentes, e é a diferença que importa:
				<strong>reconhece o produto</strong> (que medicamento é) e
				<strong>transcreve o que está impresso</strong>. A validade, o lote e a dosagem
				saem sempre da transcrição — e um número que o texto da fotografia não confirme
				aparece marcado a vermelho, porque um modelo a quem falta a validade na foto não
				responde «não sei»: responde uma data plausível.
			</p>

			<div v-if="ai.warning" class="mc-warn">{{ ai.warning }}</div>

			<form class="mc-form" @submit.prevent="sendToAi">
				<input ref="aiInput" type="file" accept="image/*" multiple capture="environment"
					@change="pickAi">
				<NcButton type="primary" native-type="submit" :disabled="!aiFiles.length || aiBusy">
					{{ aiFiles.length > 1 ? `Catalogar ${aiFiles.length} fotos` : 'Catalogar foto' }}
				</NcButton>
			</form>
			<p class="mc-hint">
				As fotos ficam em <strong>{{ ai.folder }}</strong>, nos teus ficheiros — é de lá
				que a IA as lê, e ficam como prova de onde a validade saiu.
			</p>

			<div v-if="job" class="mc-note">
				<p v-if="job.status === 'pending' || job.status === 'running'">
					<NcLoadingIcon :size="20" style="display:inline-block;vertical-align:middle" />
					A ler {{ job.files.length === 1 ? 'a fotografia' : job.files.length + ' fotografias' }}…
					<template v-if="stageLabels.length"> ({{ stageLabels.join(', ') }})</template>
				</p>
				<p v-else-if="job.status === 'failed'">{{ job.error }}</p>
				<p class="mc-hint" style="margin:0">
					Podes fechar isto e ir fazer outra coisa — um modelo local leva o tempo que
					leva, e o Nextcloud avisa-te quando terminar. A leitura fica em
					<strong>leitura #{{ job.id }}</strong>.
				</p>
			</div>
		</template>
		<div v-else-if="ai" class="mc-warn">
			<strong>Este servidor não tem IA capaz de ler imagens.</strong> {{ ai.reason }}
		</div>

		<!-- ----------------------------------------------- Código em texto -->

		<h3>Pelo conteúdo do código</h3>
		<p class="mc-hint">
			As caixas de medicamentos sujeitos a receita trazem um <strong>DataMatrix</strong> — o
			quadrado de pontos — com o código do produto, o lote e a <strong>validade</strong>.
			Qualquer app de leitura de códigos do telefone serve: lê o DataMatrix e cola aqui o
			que ela devolver. Este caminho é o mais fiável de todos, porque o código vem
			descodificado e não lido.
		</p>
		<form class="mc-form" @submit.prevent="readCode">
			<NcTextField v-model="payload" label="Conteúdo do código"
				placeholder="010560123456789717280331..." style="min-width:340px" />
			<NcButton type="primary" native-type="submit" :disabled="!payload.trim() || busy">
				Interpretar
			</NcButton>
		</form>

		<!-- --------------------------------- Leitor de códigos por imagem -->

		<template v-if="status && status.available">
			<h3>Por fotografia, com leitor de códigos</h3>
			<p class="mc-hint">
				Aqui a imagem vai a um serviço que descodifica o DataMatrix. Quando funciona é
				exato, como colar o código à mão.
			</p>
			<form class="mc-form" @submit.prevent="readPhotos">
				<input ref="fileInput" type="file" accept="image/*" multiple capture="environment"
					@change="pick">
				<NcButton type="primary" native-type="submit" :disabled="!files.length || busy">
					{{ files.length > 1 ? `Ler ${files.length} fotos` : 'Ler foto' }}
				</NcButton>
			</form>
		</template>

		<NcLoadingIcon v-if="busy" :size="32" />

		<!-- ------------------------------------------------------- Proposta -->

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
							<span v-if="unverified(field.key)" class="mc-tag mc-tag-expired"
								title="O texto da fotografia não contém este valor.">
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

			<div v-if="unverifiedList.length" class="mc-warn">
				<strong>Vai ver a caixa antes de gravar.</strong>
				Não foi possível confirmar no texto da fotografia:
				{{ unverifiedList.map(labelOf).join(', ') }}.
			</div>

			<div v-if="!form.name && !form.medicineId" class="mc-warn">
				Falta o nome do medicamento. Escreve-o uma vez — havendo código, da próxima a
				mesma caixa preenche-se sozinha.
			</div>

			<details v-if="result.sources" class="mc-note">
				<summary>O que cada fonte disse</summary>
				<pre style="white-space:pre-wrap;font-size:90%">{{ JSON.stringify(result.sources, null, 2) }}</pre>
			</details>

			<div class="mc-form" style="margin-top:16px">
				<NcButton type="primary" :disabled="applying || (!form.name && !form.medicineId)"
					@click="apply">
					Guardar no armário
				</NcButton>
				<NcButton type="tertiary" @click="reset">Descartar</NcButton>
			</div>
		</template>

		<!-- -------------------------------------------- Leituras anteriores -->

		<template v-if="recent.length">
			<h3>Leituras anteriores</h3>
			<table class="mc-table">
				<thead><tr><th>#</th><th>Fotos</th><th>Estado</th><th>Quando</th><th /></tr></thead>
				<tbody>
					<tr v-for="entry in recent" :key="entry.id">
						<td>{{ entry.id }}</td>
						<td>{{ entry.files.length }}</td>
						<td>
							<span class="mc-tag" :class="statusClass(entry.status)">
								{{ statusLabel(entry) }}
							</span>
						</td>
						<td>{{ when(entry.createdAt) }}</td>
						<td>
							<NcButton v-if="entry.status === 'done'" type="tertiary"
								@click="openJob(entry.id)">
								Ver proposta
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'

const router = useRouter()

const status = ref(null)
const ai = ref(null)
const payload = ref('')
const files = ref([])
const aiFiles = ref([])
const fileInput = ref(null)
const aiInput = ref(null)
const result = ref(null)
const job = ref(null)
const recent = ref([])
const busy = ref(false)
const aiBusy = ref(false)
const applying = ref(false)

let poll = null

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

const sourceOf = (key) => result.value?.proposal?.fields?.[key]?.from ?? '—'

// As fases da IA ditas por aquilo que fazem, nao pelo nome tecnico: a quem
// espera interessa saber o que esta a acontecer.
const stageLabels = computed(() => {
	const names = { ocr: 'a transcrever o texto', vision: 'a reconhecer o produto' }
	return Object.entries(job.value?.stages ?? {})
		.filter(([, stage]) => stage.status === 'waiting')
		.map(([name]) => names[name] ?? name)
})

const pick = (event) => {
	files.value = Array.from(event.target.files ?? [])
}

const pickAi = (event) => {
	aiFiles.value = Array.from(event.target.files ?? [])
}

const load = (data) => {
	result.value = data
	for (const key of Object.keys(form)) {
		delete form[key]
	}
	Object.assign(form, data.proposal?.values ?? {})
}

const emptyProposal = (failed) => ({
	proposal: { warnings: [], fields: {}, needsReview: [], unverified: [], values: {} },
	failed,
})

// ------------------------------------------------------------------ IA

const sendToAi = async () => {
	aiBusy.value = true
	try {
		job.value = await api.aiScan(aiFiles.value)
		aiFiles.value = []
		if (aiInput.value) {
			aiInput.value.value = ''
		}
		startPolling()
		await refreshRecent()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível enviar as fotos.')
	} finally {
		aiBusy.value = false
	}
}

const startPolling = () => {
	stopPolling()
	// Sondar e so para quem esta a olhar. Quem fechar a pagina e avisado pela
	// notificacao do Nextcloud -- o trabalho nao depende deste separador.
	poll = setInterval(async () => {
		if (!job.value) {
			stopPolling()
			return
		}
		try {
			const fresh = await api.aiScanDetail(job.value.id)
			job.value = fresh
			if (fresh.status === 'done' || fresh.status === 'failed') {
				stopPolling()
				await refreshRecent()
				if (fresh.status === 'done' && fresh.proposal?.proposal) {
					load(fresh.proposal)
				} else if (fresh.status === 'failed') {
					showError(fresh.error ?? 'A leitura não concluiu.')
				}
			}
		} catch (error) {
			stopPolling()
		}
	}, 3000)
}

const stopPolling = () => {
	if (poll) {
		clearInterval(poll)
		poll = null
	}
}

const openJob = async (id) => {
	try {
		const fresh = await api.aiScanDetail(id)
		job.value = fresh
		if (fresh.proposal?.proposal) {
			load(fresh.proposal)
		}
	} catch (error) {
		showError('Não foi possível abrir essa leitura.')
	}
}

const refreshRecent = async () => {
	try {
		recent.value = await api.aiScans()
	} catch (error) {
		recent.value = []
	}
}

const statusClass = (value) => ({
	done: 'mc-tag-ok',
	failed: 'mc-tag-expired',
	running: 'mc-tag-expiring',
	pending: 'mc-tag-unknown',
}[value] ?? 'mc-tag-unknown')

const statusLabel = (entry) => {
	if (entry.status === 'done') {
		const review = entry.proposal?.proposal?.needsReview?.length ?? 0
		return review === 0 ? 'pronta' : `pronta, ${review} a confirmar`
	}
	return { failed: 'falhou', running: 'a ler', pending: 'em fila' }[entry.status] ?? entry.status
}

const when = (iso) => (iso ? new Date(iso).toLocaleString('pt-PT') : '—')

// -------------------------------------------------------- Código e leitor

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
			result.value = emptyProposal(data.failed)
			showError('Não se encontrou nenhum código nas fotos.')
			return
		}
		load(data)
	} catch (error) {
		const body = error?.response?.data?.ocs?.data
		if (body?.failed?.length) {
			result.value = emptyProposal(body.failed)
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
	job.value = null
	payload.value = ''
	files.value = []
	aiFiles.value = []
	stopPolling()
	if (fileInput.value) {
		fileInput.value.value = ''
	}
	if (aiInput.value) {
		aiInput.value.value = ''
	}
}

onMounted(async () => {
	const [readerStatus, aiState] = await Promise.allSettled([api.scanStatus(), api.aiStatus()])

	status.value = readerStatus.status === 'fulfilled'
		? readerStatus.value
		: { available: false, reason: 'Não foi possível saber o estado do leitor.' }

	ai.value = aiState.status === 'fulfilled'
		? aiState.value
		: { available: false, reason: 'Não foi possível saber o estado da IA deste servidor.' }

	await refreshRecent()

	// Se ficou uma leitura a correr de uma visita anterior, continua-se a
	// seguir essa: fechar a pagina nao devia perder o rasto do trabalho.
	const pending = recent.value.find((e) => e.status === 'running' || e.status === 'pending')
	if (pending) {
		job.value = pending
		startPolling()
	}
})

onUnmounted(stopPolling)
</script>
