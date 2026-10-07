<template>
	<div class="mc-page">
		<h2>Visão geral</h2>
		<p class="mc-hint">
			Pela ordem em que se age: o que está mau deita-se fora hoje, o que não se sabe
			esclarece-se, e o que expira nas próximas semanas usa-se primeiro.
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="data">
			<p v-if="!data.medicineCount" class="mc-empty">
				Ainda não há medicamentos.
				<RouterLink :to="{ name: 'medicines' }">Começa por registar um.</RouterLink>
			</p>

			<template v-else>
				<section v-if="data.expired.length">
					<h3>Fora de prazo — {{ data.expired.length }}</h3>
					<div v-for="row in data.expired" :key="row.packageId" class="mc-warn mc-bad">
						<strong>{{ row.medicine }}{{ row.strength ? ' ' + row.strength : '' }}</strong>
						— expirou {{ formatDate(row.expiry.date) }}
						<template v-if="row.location"> · {{ row.location }}</template>
						<div v-if="row.reason" class="mc-hint" style="margin:4px 0 0">{{ row.reason }}</div>
					</div>
				</section>

				<section v-if="data.unknownExpiry.length">
					<h3>Sem validade que se possa afirmar — {{ data.unknownExpiry.length }}</h3>
					<p class="mc-hint">
						Falta um dado. Não é o mesmo que estar bom: é não se saber.
					</p>
					<div v-for="row in data.unknownExpiry" :key="row.packageId" class="mc-warn">
						<strong>{{ row.medicine }}{{ row.strength ? ' ' + row.strength : '' }}</strong>
						<RouterLink :to="{ name: 'medicine', params: { id: row.medicineId } }"
							style="margin-left:8px">corrigir</RouterLink>
						<div v-if="row.reason" class="mc-hint" style="margin:4px 0 0">{{ row.reason }}</div>
					</div>
				</section>

				<section v-if="data.expiringSoon.length">
					<h3>Expira nos próximos {{ data.expiringSoonDays }} dias — {{ data.expiringSoon.length }}</h3>
					<table class="mc-table">
						<thead>
							<tr><th>Medicamento</th><th>Válido até</th><th class="mc-num">Dias</th><th>Onde</th></tr>
						</thead>
						<tbody>
							<tr v-for="row in data.expiringSoon" :key="row.packageId">
								<td>
									<RouterLink :to="{ name: 'medicine', params: { id: row.medicineId } }">
										{{ row.medicine }}{{ row.strength ? ' ' + row.strength : '' }}
									</RouterLink>
								</td>
								<td>{{ formatDate(row.expiry.date) }}</td>
								<td class="mc-num">{{ row.daysLeft }}</td>
								<td>{{ row.location || '—' }}</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section v-if="data.outOfStock.length">
					<h3>Acabou — {{ data.outOfStock.length }}</h3>
					<ul>
						<li v-for="row in data.outOfStock" :key="row.medicineId">
							<RouterLink :to="{ name: 'medicine', params: { id: row.medicineId } }">
								{{ row.medicine }}{{ row.strength ? ' ' + row.strength : '' }}
							</RouterLink>
						</li>
					</ul>
				</section>

				<p v-if="nothingToDo" class="mc-empty">
					Nada a precisar de atenção: {{ data.medicineCount }} medicamento(s), todos com
					validade conhecida e dentro do prazo.
				</p>
			</template>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate } from '../utils/format.js'

const loading = ref(true)
const data = ref(null)

const nothingToDo = computed(() => data.value
	&& data.value.medicineCount > 0
	&& !data.value.expired.length
	&& !data.value.unknownExpiry.length
	&& !data.value.expiringSoon.length
	&& !data.value.outOfStock.length)

onMounted(async () => {
	try {
		data.value = await api.overview()
	} catch (error) {
		showError('Não foi possível carregar a visão geral.')
	} finally {
		loading.value = false
	}
})
</script>
