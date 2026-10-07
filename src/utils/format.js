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
 */
export const PERISHABLE_FORMS = [
	'xarope', 'suspensão', 'solução', 'colírio', 'gotas', 'pomada', 'creme',
]

export const UNITS = ['unidade', 'comprimido', 'cápsula', 'ml', 'gota', 'saqueta', 'puff', 'g']
