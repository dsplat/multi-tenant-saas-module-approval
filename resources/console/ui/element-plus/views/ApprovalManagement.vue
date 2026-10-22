<template>
  <div class="approval-page">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>审批中心</span>
          <el-button type="primary" @click="openCreate">新建</el-button>
        </div>
      </template>

      <div class="filter-bar">
        <el-input
          v-model="filters.keyword"
          placeholder="标题关键词"
          clearable
          style="width: 220px"
          @keyup.enter="load"
        />
        <el-select v-model="filters.status" placeholder="状态" clearable style="width: 150px">
          <el-option label="草稿" value="draft" />
          <el-option label="已发布" value="published" />
          <el-option label="已归档" value="archived" />
        </el-select>
        <el-button type="primary" @click="load">查询</el-button>
      </div>

      <el-table v-loading="loading" :data="rows" border stripe>
        <el-table-column prop="title" label="标题" min-width="220" show-overflow-tooltip />
        <el-table-column prop="slug" label="Slug" width="180" show-overflow-tooltip />
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="90" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pager"
        layout="total, prev, pager, next"
        :total="total"
        :current-page="page"
        :page-size="perPage"
        @current-change="handlePageChange"
      />
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="editingId ? '编辑审批中心' : '新建审批中心'"
      width="560px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="80px">
        <el-form-item label="标题" prop="title">
          <el-input v-model="form.title" maxlength="255" show-word-limit />
        </el-form-item>
        <el-form-item label="Slug" prop="slug">
          <el-input v-model="form.slug" placeholder="留空则不设置" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="form.status" style="width: 100%">
            <el-option label="草稿" value="draft" />
            <el-option label="已发布" value="published" />
            <el-option label="已归档" value="archived" />
          </el-select>
        </el-form-item>
        <el-form-item label="内容" prop="content">
          <el-input v-model="form.content" type="textarea" :rows="4" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  createApproval,
  deleteApproval,
  getApprovalList,
  updateApproval,
} from '@modules/Approval/api/approval'
import type { Approval, SaveApprovalData } from '@modules/Approval/api/approval'

const STATUS_TEXT: Record<string, string> = {
  draft: '草稿',
  published: '已发布',
  archived: '已归档',
}

const loading = ref(false)
const saving = ref(false)
const rows = ref<Approval[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)

const filters = reactive({ keyword: '', status: '' })

const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const formRef = ref<FormInstance>()

const form = reactive<SaveApprovalData>({
  title: '',
  slug: '',
  content: '',
  status: 'draft',
  sort: 0,
})

const formRules: FormRules = {
  title: [{ required: true, message: '请输入标题', trigger: 'blur' }],
}

function statusLabel(status: string): string {
  return STATUS_TEXT[status] ?? status
}

function statusTagType(status: string): 'info' | 'success' | 'warning' {
  if (status === 'published') return 'success'
  if (status === 'archived') return 'warning'
  return 'info'
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await getApprovalList({
      page: page.value,
      pageSize: perPage.value,
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
    })
    rows.value = result.data
    total.value = result.total
  } catch {
    ElMessage.error('加载审批中心列表失败')
  } finally {
    loading.value = false
  }
}

function handlePageChange(next: number): void {
  page.value = next
  load()
}

function resetForm(): void {
  form.title = ''
  form.slug = ''
  form.content = ''
  form.status = 'draft'
  form.sort = 0
}

function openCreate(): void {
  editingId.value = null
  resetForm()
  dialogVisible.value = true
}

function openEdit(row: Approval): void {
  editingId.value = row.approval_id
  form.title = row.title
  form.slug = row.slug ?? ''
  form.content = row.content ?? ''
  form.status = row.status
  form.sort = row.sort
  dialogVisible.value = true
}

async function save(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  saving.value = true
  try {
    if (editingId.value) {
      await updateApproval(editingId.value, { ...form })
      ElMessage.success('已保存')
    } else {
      await createApproval({ ...form })
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    await load()
  } catch {
    ElMessage.error('保存失败')
  } finally {
    saving.value = false
  }
}

async function remove(row: Approval): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认删除「${row.title}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }

  try {
    await deleteApproval(row.approval_id)
    ElMessage.success('已删除')
    await load()
  } catch {
    ElMessage.error('删除失败')
  }
}

onMounted(load)
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.filter-bar {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
