<template>
	<div class="mc-page">
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="med">
			<div class="mc-head">
				<h2>{{ med.name }}{{ med.strength ? ' ' + med.strength : '' }}</h2>
				<NcButton type="button" variant="primary" @click="startPackage">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					Registar caixa
				</NcButton>
			</div>

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
					<NcButton type="button" @click="startDays">Indicar os dias</NcButton>
				</div>
			</div>

			<h3>Caixas</h3>
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

				<div class="mc-form" style="margin:12px 0 0">
					<NcButton v-if="!p.openedAt" type="button" @click="markOpened(p)">
						Marcar como aberta hoje
					</NcButton>
					<NcButton v-else type="button" variant="tertiary" @click="clearOpened(p)">
						Não está aberta
					</NcButton>
					<NcButton type="button" @click="startTake(p)">
						<template #icon>
							<MinusIcon :size="20" />
						</template>
						Dar baixa
					</NcButton>
					<NcButton type="button" variant="tertiary" @click="removePackage(p)">Apagar</NcButton>
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

			<!-- ------------------------------------------- Registar caixa -->

			<FormDialog v-model:open="addingPackage" name="Registar caixa"
				submit-label="Registar" :busy="savingPackage" @submit="addPackage">
				<NcTextField v-model="pkg.unitsTotal" type="number" step="any"
					:label="`Quantas ${med.unit}`" placeholder="20" />
				<NcDateTimePickerNative v-model="pkg.expiresAt" label="Validade na caixa" type="date" />
				<NcTextField v-model="pkg.batch" label="Lote" placeholder="opcional" />
				<NcTextField v-model="pkg.location" label="Onde está" placeholder="gaveta da cozinha" />
				<p class="mc-hint" style="margin:0">
					O total serve para a conta do que resta. Sem ele, "resta um quarto" não quer
					dizer nada.
				</p>
			</FormDialog>

			<!-- ----------------------------------------------- Dar baixa -->

			<FormDialog v-model:open="taking" name="Dar baixa"
				submit-label="Guardar" :busy="savingTake"
				:disabled="consumed === ''" @submit="takeFrom">
				<template v-if="takeTarget">
					<p class="mc-hint" style="margin:0">
						<template v-if="takeTarget.unitsLeft !== null">
							Restam {{ formatNumber(takeTarget.unitsLeft) }}
							<template v-if="takeTarget.unitsTotal">
								de {{ formatNumber(takeTarget.unitsTotal) }}
							</template>
							{{ med.unit }}{{ takeTarget.location ? ` · ${takeTarget.location}` : '' }}.
						</template>
						<template v-else>
							{{ takeTarget.location || 'Esta caixa' }}
						</template>
					</p>

					<!-- Nao se sabe quantas restavam: tirar duas de um numero
					     desconhecido nao da zero, da um numero desconhecido. Por
					     isso aqui pergunta-se quantas ficaram, nao quantas saem. -->
					<div v-if="takeTarget.unitsLeft === null" class="mc-warn" style="margin:0">
						Não se sabe quantas restavam nesta caixa. Subtrair de um número
						desconhecido daria um número inventado — escreve quantas ficam.
					</div>

					<NcTextField v-model="consumed" type="number" step="any" min="0"
						:label="takeTarget.unitsLeft === null
							? `Quantas ${med.unit} ficam`
							: `Dar baixa de quantas ${med.unit}`"
						:placeholder="takeTarget.unitsLeft === null ? '12' : '1'" />
				</template>
			</FormDialog>

			<!-- --------------------------------------- Dias apos abertura -->

			<FormDialog v-model:open="editingDays" name="Dias após abertura"
				submit-label="Guardar" :busy="savingDays" :disabled="daysInput === ''"
				@submit="saveDays">
				<p class="mc-hint" style="margin:0">
					Quantos dias um {{ med.form }} aberto continua bom. Vem no folheto, em
					"depois de aberto" ou "após primeira abertura".
				</p>
				<NcTextField v-model="daysInput" type="number" min="1"
					label="Dias após abertura" placeholder="28" />
			</FormDialog>
		</template>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import MinusIcon from 'vue-material-design-icons/Minus.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import FormDialog from '../components/FormDialog.vue'
import api from '../api/client.js'
import { formatDate, formatNumber, toIsoDate, isPerishableForm, STATUS_LABEL } from '../utils/format.js'

const props = defineProps({ id: { type: [String, Number], required: true } })

const loading = ref(true)
const med = ref(null)
const uses = ref([])

const addingPackage = ref(false)
const savingPackage = ref(false)
const BLANK_PKG = { unitsTotal: '', expiresAt: null, batch: '', location: '' }
const pkg = reactive({ ...BLANK_PKG })

const taking = ref(false)
const savingTake = ref(false)
const takeTarget = ref(null)
const consumed = ref('')

const editingDays = ref(false)
const savingDays = ref(false)
const daysInput = ref('')

const needsOpeningDays = computed(() => isPerishableForm(med.value?.form))

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

const startDays = () => {
	daysInput.value = ''
	editingDays.value = true
}

const saveDays = async () => {
	const days = Number(daysInput.value)
	if (!days || days <= 0) {
		return
	}
	savingDays.value = true
	try {
		await api.updateMedicine(props.id, { daysAfterOpening: days })
		editingDays.value = false
		await load()
	} catch (error) {
		showError('Não foi possível guardar.')
	} finally {
		savingDays.value = false
	}
}

const startPackage = () => {
	Object.assign(pkg, BLANK_PKG)
	addingPackage.value = true
}

const addPackage = async () => {
	savingPackage.value = true
	try {
		await api.addPackage(props.id, {
			unitsTotal: pkg.unitsTotal === '' ? null : Number(pkg.unitsTotal),
			expiresAt: toIsoDate(pkg.expiresAt),
			batch: pkg.batch.trim() || null,
			location: pkg.location.trim() || null,
		})
		addingPackage.value = false
		showSuccess('Caixa registada.')
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível registar a caixa.')
	} finally {
		savingPackage.value = false
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

const startTake = (p) => {
	takeTarget.value = p
	consumed.value = ''
	taking.value = true
}

const takeFrom = async () => {
	const p = takeTarget.value
	const amount = Number(consumed.value)
	if (!p || consumed.value === '' || Number.isNaN(amount) || amount < 0) {
		return
	}

	// Com o que restava conhecido, o campo e quanto sai e a app subtrai. Sem
	// ele, o campo e quanto fica -- escrito por quem tem a caixa na mao.
	const left = p.unitsLeft === null ? amount : Math.max(0, p.unitsLeft - amount)
	if (p.unitsLeft !== null && amount <= 0) {
		return
	}

	savingTake.value = true
	try {
		await api.updatePackage(p.id, { unitsLeft: left })
		taking.value = false
		await load()
	} catch (error) {
		showError('Não foi possível dar baixa.')
	} finally {
		savingTake.value = false
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
