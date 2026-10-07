import { createRouter, createWebHashHistory } from 'vue-router'

import OverviewView from '../views/OverviewView.vue'
import MedicinesView from '../views/MedicinesView.vue'
import MedicineView from '../views/MedicineView.vue'
import EpisodesView from '../views/EpisodesView.vue'
import PeopleView from '../views/PeopleView.vue'

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'overview', component: OverviewView },
		{ path: '/medicines', name: 'medicines', component: MedicinesView },
		{ path: '/medicines/:id', name: 'medicine', component: MedicineView, props: true },
		{ path: '/episodes', name: 'episodes', component: EpisodesView },
		{ path: '/people', name: 'people', component: PeopleView },
	],
})
