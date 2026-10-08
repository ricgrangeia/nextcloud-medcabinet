<template>
	<NcContent app-name="medcabinet">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :to="{ name: 'overview' }" name="Visão geral">
					<template #icon><AlertIcon :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :to="{ name: 'medicines' }" name="Medicamentos">
					<template #icon><PillIcon :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :to="{ name: 'scan' }" name="Registar por leitura">
					<template #icon><ScanIcon :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :to="{ name: 'episodes' }" name="Para que serviram">
					<template #icon><HistoryIcon :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :to="{ name: 'people' }" name="Pessoas">
					<template #icon><AccountIcon :size="20" /></template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<RouterView />
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'

import AlertIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import PillIcon from 'vue-material-design-icons/Pill.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import ScanIcon from 'vue-material-design-icons/LineScan.vue'
import AccountIcon from 'vue-material-design-icons/AccountMultiple.vue'
</script>

<style>
.mc-page {
	padding: 24px;
	max-width: 1100px;
}

.mc-page h2 { margin: 0 0 4px; font-size: 22px; }
.mc-page h3 { margin: 28px 0 8px; font-size: 17px; }

.mc-hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 20px;
	max-width: 70ch;
	line-height: 1.5;
}

.mc-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 12px;
	background: var(--color-main-background);
}

.mc-card-head {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 12px;
	margin-bottom: 8px;
}

.mc-card-head strong { font-size: 16px; }

/* Titulo da pagina e o botao que abre o formulario. O botao fica aqui e nao
   por cima da lista: assim a lista comeca onde se espera, e nao depois de um
   formulario que se usa de vez em quando. */
.mc-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 4px;
}

.mc-head h2 { margin: 0; }

/* Campos dentro de uma janela: um por linha, a largura toda. Em linha, como
   na pagina, um campo de validade ficava ao lado de um de quantidade com o
   mesmo aspecto. */
.mc-fields {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 2px 8px;
	min-width: min(420px, 100%);
}

.mc-fields > .mc-hint { max-width: none; }

.mc-form {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.mc-form > * { min-width: 150px; }

.mc-table { width: 100%; border-collapse: collapse; }

.mc-table th,
.mc-table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
	font-variant-numeric: tabular-nums;
}

.mc-table th {
	font-size: 12px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.mc-table td.mc-num, .mc-table th.mc-num { text-align: right; }

.mc-empty { color: var(--color-text-maxcontrast); padding: 24px 0; }

.mc-warn {
	border-left: 3px solid var(--color-warning);
	padding: 8px 12px;
	margin: 12px 0;
	background: var(--color-background-hover);
	border-radius: 0 var(--border-radius) var(--border-radius) 0;
}

.mc-bad { border-left-color: var(--color-error); }

/* Um aviso diz "atencao"; isto diz "esta a acontecer" ou "ficou guardado".
   Nao leva a barra de aviso, para o amarelo continuar a significar algo. */
.mc-note {
	border: 1px solid var(--color-border);
	padding: 8px 12px;
	margin: 12px 0;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.mc-note > p { margin: 4px 0; }
.mc-note summary { cursor: pointer; }

.mc-form-block { display: block; }

.mc-textarea {
	width: 100%;
	max-width: 640px;
	font-family: monospace;
	padding: 8px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, var(--border-radius));
	background: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
}

/* Estado de uma embalagem. A cor nao e o unico sinal: o rotulo vai sempre
   escrito, para quem nao distinga as cores. */
.mc-tag {
	display: inline-block;
	font-size: 12px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.mc-tag-expired { background: var(--color-error); color: #fff; }
.mc-tag-expiring { background: var(--color-warning); color: #000; }
.mc-tag-unknown { background: var(--color-background-darker); }
.mc-tag-ok { background: var(--color-success); color: #fff; }

.mc-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
}

.mc-stat .mc-stat-label {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: .03em;
}

.mc-stat .mc-stat-value { font-size: 20px; font-weight: 600; }
</style>
