import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Cliente da API OCS da app.
 *
 * A interface usa exactamente os mesmos endpoints que um agente externo
 * usaria -- nao ha um conjunto de rotas para o SPA e outro para a API.
 */
const base = generateOcsUrl('apps/medcabinet/api/v1')

const ocs = axios.create({ headers: { 'OCS-APIRequest': 'true' } })
const unwrap = (response) => response.data?.ocs?.data

const request = async (method, path, { params, data } = {}) => {
	const response = await ocs.request({ method, url: `${base}${path}`, params, data })
	return unwrap(response)
}

export default {
	overview: () => request('get', '/overview'),

	listPeople: () => request('get', '/people'),
	createPerson: (data) => request('post', '/people', { data }),
	updatePerson: (id, data) => request('put', `/people/${id}`, { data }),
	deletePerson: (id) => request('delete', `/people/${id}`),

	listMedicines: (q) => request('get', '/medicines', { params: { q } }),
	getMedicine: (id) => request('get', `/medicines/${id}`),
	createMedicine: (data) => request('post', '/medicines', { data }),
	updateMedicine: (id, data) => request('put', `/medicines/${id}`, { data }),
	deleteMedicine: (id) => request('delete', `/medicines/${id}`),
	medicineUses: (id) => request('get', `/medicines/${id}/uses`),

	addPackage: (medicineId, data) => request('post', `/medicines/${medicineId}/packages`, { data }),
	updatePackage: (id, data) => request('put', `/packages/${id}`, { data }),
	deletePackage: (id) => request('delete', `/packages/${id}`),

	listEpisodes: (params) => request('get', '/episodes', { params }),
	getEpisode: (id) => request('get', `/episodes/${id}`),
	createEpisode: (data) => request('post', '/episodes', { data }),
	updateEpisode: (id, data) => request('put', `/episodes/${id}`, { data }),
	deleteEpisode: (id) => request('delete', `/episodes/${id}`),
	addEpisodeItem: (id, data) => request('post', `/episodes/${id}/items`, { data }),
	deleteEpisodeItem: (id) => request('delete', `/episode-items/${id}`),
}
