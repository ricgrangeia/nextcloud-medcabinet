<template>
	<div class="mc-page">
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="med">
			<h2>{{ med.name }}{{ med.strength ? ' ' + med.strength : '' }}</h2>
			<p class="mc-hint">
				{{ med.substance || 'sem substância ativa registada' }}
				<template v-if="med.form"> · {{ med.form }}</template>
				· conta-se em {{ med.unit }}
				<template v-if="med.daysAfterOpening">
					· válido {{ med.daysAfterOpening }} dias depois de aberto
				</template>
			</p>

			<div v-if="needsOpeningDays && !med.daysAfterOpening" class="mc-warn">
				<strong>Falta os dias após abertura.</strong>
				Num {{ med.form }}, a validade impressa deixa de valer quando se abre — e sem este
				número a app não pode dizer se uma embalagem aberta está boa. Vem no folheto.
				<div class="mc-form" style="margin:8px 0 0">
					<NcTextField v-model="daysInput" type="number" label="Dias após abertura" placeholder="28" />
					<NcButton @click="saveDays">Guardar</NcButton>
				</div>
			</div>

			<h3>Caixas</h3>
			<form class="mc-form" @submit.prevent="addPackage">
				<NcTextField v-model="pkg.unitsTotal" type="number" step="any"
					:label="`Quantas ${med.unit}`" placeholder="20" />
				<NcDateTimePickerNative v-model="pkg.expiresAt" label="Validade na caixa" type="date" />
				<NcTextField v-model="pkg.batch" label="Lote" placeholder="opcional" />
				<NcTextField v-model="pkg.location" label="Onde está" placeholder="gaveta da cozinha" />
				<NcButton type="primary" native-type="submit">Registar caixa</NcButton>
			</form>

			<p v-if="!med.packages.length" class="mc-empty">Nenhuma caixa registada.</p>

			<div v-for="p in med.packages" :key="p.id" class="mc-card">
				<div class="mc-card-head">
					<strong>
						<span :class="['mc-tag', 'mc-tag-' + p.status]">{{ STATUS_LABEL[p.status] }}</span>
						<span v-if="p.id === med.useFirstPackageId" class="mc-tag" style="margin-left:6px">
							usar esta primeiro
						</span>
					</strong>
					<span class="mc-hint" style="margin:0">
						{{ p.location || 'sem local' }}
						<template v-if="p.batch"> · lote {{ p.batch }}</template>
						<template v-if="p.source !== 'manual'"> · lido da caixa</template>
					</span>
				</div>

				<div v-if="p.reason" class="mc-warn">{{ p.reason }}</div>

				<div class="mc-grid">
					<div class="mc-stat">
						<span class="mc-stat-label">Válida até</span>
						<span class="mc-stat-value" style="font-size:16px">{{ formatDate(p.expiry.date) }}</span>
						<span v-if="p.expiry.source === 'opened'" class="mc-stat-label">
							pela abertura · caixa diz {{ formatDate(p.expiry.printed) }}
						</span>
						<span v-else-if="p.daysLeft !== null" class="mc-stat-label">
							{{ p.daysLeft >= 0 ? `faltam ${p.daysLeft} dias` : `há ${-p.daysLeft} dias` }}
						</span>
					</div>
					<div class="mc-stat">
						<span class="mc-stat-label">Resta</span>
						<span class="mc-stat-value">
							{{ p.unitsLeft === null ? '—' : formatNumber(p.unitsLeft) }}
						</span>
						<span class="mc-stat-label">
							{{ p.unitsTotal ? `de ${formatNumber(p.unitsTotal)} ${med.unit}` : med.unit }}
						</span>
					</div>
					<div class="mc-stat">
						<span class="mc-stat-label">Aberta em</span>
						<span class="mc-stat-value" style="font-size:16px">{{ formatDate(p.openedAt) }}</span>
					</div>
				</div>

				<div class="mc-form" style="margin-top:12px">
					<NcButton v-if="!p.openedAt" @click="markOpened(p)">Marcar como aberta hoje</NcButton>
					<NcButton v-else type="tertiary" @click="clearOpened(p)">Não está aberta</NcButton>
					<NcTextField v-model="consume[p.id]" type="number" step="any"
						label="Dar baixa de" :placeholder="med.unit" style="min-width:120px" />
					<NcButton @click="takeFrom(p)">Dar baixa</NcButton>
					<NcButton type="tertiary" @click="removePackage(p)">Apagar</NcButton>
				</div>
			</div>

			<h3>Para que serviu</h3>
			<p v-if="!uses.length" class="mc-empty">
				Nunca foi registado num episódio.
				<RouterLink :to="{ name: 'episodes' }">Registar um.</RouterLink>
			</p>
			<table v-else class="mc-table">
				<thead>
					<tr><th>Quando</th><th>Para quem</th><th>Motivo</th><th>Posologia</th><th>Indicado por</th></tr>
				</thead>
				<tbody>
					<tr v-for="use in uses" :key="use.episodeId">
						<td>{{ formatDate(use.startedAt) }}</td>
						<td>{{ use.person || '—' }}</td>
						<td>{{ use.reason }}</td>
						<td>{{ use.posology || '—' }}</td>
						<td>{{ use.prescriber || '—' }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, formatNumber, toIsoDate, STATUS_LABEL, PERISHABLE_FORMS } from '../utils/format.js'

const props = defineProps({ id: { type: [String, Number], required: true } })

const loading = ref(true)
const med = ref(null)
const uses = ref([])
const daysInput = ref('')
const consume = reactive({})
const pkg = reactive({ unitsTotal: '', expiresAt: null, batch: '', location: '' })

const needsOpeningDays = computed(() => PERISHABLE_FORMS.includes(med.value?.form))

const load = async () => {
	loading.value = true
	try {
		const [detail, usesData] = await Promise.all([
			api.getMedicine(props.id), api.medicineUses(props.id),
		])
		med.value = detail
		uses.value = usesData.uses
	} catch (error) {
		showError('Não foi possível carregar o medicamento.')
	} finally {
		loading.value = false
	}
}

const saveDays = async () => {
	try {
		await api.updateMedicine(props.id, { daysAfterOpening: Number(daysInput.value) })
		daysInput.value = ''
		await load()
	} catch (error) {
		showError('Não foi possível guardar.')
	}
}

const addPackage = async () => {
	try {
		await api.addPackage(props.id, {
			unitsTotal: pkg.unitsTotal === '' ? null : Number(pkg.unitsTotal),
			expiresAt: toIsoDate(pkg.expiresAt),
			batch: pkg.batch.trim() || null,
			location: pkg.location.trim() || null,
		})
		pkg.unitsTotal = ''
		pkg.batch = ''
		showSuccess('Caixa registada.')
		await load()
	} catch (error) {
		showError('Não foi possível registar a caixa.')
	}
}

const markOpened = async (p) => {
	try {
		await api.updatePackage(p.id, { openedAt: toIsoDate(new Date()) })
		await load()
	} catch (error) {
		showError('Não foi possível marcar.')
	}
}

const clearOpened = async (p) => {
	try {
		await api.updatePackage(p.id, { openedAt: '' })
		await load()
	} catch (error) {
		showError('Não foi possível alterar.')
	}
}

const takeFrom = async (p) => {
	const amount = Number(consume[p.id])
	if (!amount || amount <= 0) {
		return
	}
	const left = Math.max(0, (p.unitsLeft ?? 0) - amount)
	try {
		await api.updatePackage(p.id, { unitsLeft: left })
		consume[p.id] = ''
		await load()
	} catch (error) {
		showError('Não foi possível dar baixa.')
	}
}

const removePackage = async (p) => {
	if (!window.confirm('Apagar esta caixa?')) {
		return
	}
	try {
		await api.deletePackage(p.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar.')
	}
}

onMounted(load)
</script>
