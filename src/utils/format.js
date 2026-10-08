export const toIsoDate = (date) => {
	if (!date) {
		return null
	}
	const d = date instanceof Date ? date : new Date(date)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export const formatDate = (value) => {
	if (!value) {
		return '—'
	}
	const [y, m, d] = value.split('-')
	return `${d}/${m}/${y}`
}

export const formatNumber = (value, digits = 2) => {
	if (value === null || value === undefined) {
		return '—'
	}
	return Number(value).toLocaleString('pt-PT', { maximumFractionDigits: digits })
}

export const STATUS_LABEL = {
	ok: 'válido',
	expiring: 'a expirar',
	expired: 'fora de prazo',
	unknown: 'validade desconhecida',
	discarded: 'descartada',
}

export const FORMS = [
	'comprimido', 'cápsula', 'xarope', 'suspensão', 'solução',
	'colírio', 'gotas', 'pomada', 'creme', 'saqueta', 'supositório',
	'inalador', 'injetável', 'adesivo',
]

/**
 * Formas em que a validade muda depois de abrir. A interface usa isto para
 * insistir no campo "dias após abertura" -- sem ele, o aviso de validade
 * mente nestas formas.
 *
 * Escritas sem acento porque a comparação é feita sem acentos: a lista acima
 * diz "colírio" e o que vem de uma leitura diz "colirio". Comparar ao pé da
 * letra deixava passar sem aviso exactamente as caixas lidas da embalagem --
 * e um colírio sem dias após abertura é o caso em que o aviso mais faz falta.
 */
export const PERISHABLE_FORMS = [
	'xarope', 'suspensao', 'solucao', 'colirio', 'gotas', 'pomada', 'creme',
]

const deaccent = (value) => String(value)
	.normalize('NFD')
	.replace(/[\u0300-\u036f]/g, '')
	.toLowerCase()

/** Se esta forma perde validade ao ser aberta, com ou sem acentos. */
export const isPerishableForm = (form) => {
	if (!form) {
		return false
	}
	const folded = deaccent(form)
	return PERISHABLE_FORMS.some((needle) => folded.includes(needle))
}

export const UNITS = ['unidade', 'comprimido', 'cápsula', 'ml', 'gota', 'saqueta', 'puff', 'g']
