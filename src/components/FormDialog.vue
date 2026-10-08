<template>
	<NcDialog v-if="open"
		:name="name"
		:open="open"
		size="normal"
		is-form
		:close-on-click-outside="false"
		:additional-trap-elements="TRAP"
		@update:open="close"
		@submit="$emit('submit')">
		<div class="mc-fields">
			<slot />
		</div>

		<template #actions>
			<NcButton type="button" variant="tertiary" :disabled="busy" @click="close">
				Cancelar
			</NcButton>
			<NcButton type="submit" variant="primary" :disabled="busy || disabled">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

/**
 * Um formulario numa janela, para tudo o que se acrescenta.
 *
 * Esta separado da lista de proposito. Um formulario por cima de uma lista
 * disputa o espaco com ela: a lista e o que se ve todos os dias, e o
 * formulario e o que se usa de vez em quando. E, numa janela, os campos
 * ficam um por linha com o nome ao lado -- o que importa quando o campo e
 * uma validade ou uma quantidade e trocar de coluna muda o significado.
 *
 * Fechar nao e cancelar a meio de gravar: enquanto `busy`, os dois botoes
 * estao desativados. E um erro **nao fecha** a janela, para nao se perder o
 * que foi escrito -- a mensagem aparece e os campos ficam como estavam.
 */
const props = defineProps({
	/** Titulo da janela. */
	name: { type: String, required: true },
	/** Se esta aberta (usar com v-model:open). */
	open: { type: Boolean, default: false },
	/** Texto do botao que grava. */
	submitLabel: { type: String, default: 'Guardar' },
	/** Verdadeiro enquanto o pedido esta a correr. */
	busy: { type: Boolean, default: false },
	/** Verdadeiro quando falta o minimo para gravar. */
	disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:open', 'submit'])

/**
 * O NcSelect abre a lista no fim do `body`, fora da janela. Sem isto, a
 * armadilha de foco da janela nao a considera sua e escolher uma forma com o
 * teclado fica impossivel.
 */
const TRAP = ['.vs__dropdown-menu']

const close = () => {
	if (!props.busy) {
		emit('update:open', false)
	}
}
</script>
