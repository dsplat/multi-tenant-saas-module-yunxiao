import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

const routes: RouteRecordRaw[] = [
  // 云效集成
  {
    path: 'yunxiao',
    name: 'YunxiaoManagement',
    component: view('Yunxiao', 'YunxiaoManagement'),
    meta: { title: '云效集成', permission: 'yunxiao.view' },
  },
]

export default routes
