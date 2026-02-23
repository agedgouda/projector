import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import projectTypeRoutes from '@/routes/project-types'

export function useProjectTypeForm(editData: any | null, onSuccess: () => void) {
    const form = useForm({
        name: '',
        icon: 'Briefcase',
        document_schema: [{ label: 'Notes', key: 'intake', is_task: false }] as DocumentSchemaItem[],
        workflow: [] as WorkflowStep[],
        lifecycle_steps: [] as LifecycleStep[],
        organization_id: '' as string,
    })

    const hydrate = (data: any) => {
        form.name = data.name
        form.icon = data.icon ?? 'Briefcase'
        form.document_schema = (data.document_schema ?? []).map((doc: DocumentSchemaItem) => ({
            ...doc,
            is_task: !!doc.is_task,
        }))
        form.workflow = data.workflow ? [...data.workflow] : []
        form.lifecycle_steps = data.lifecycle_steps ? [...data.lifecycle_steps] : []
        form.organization_id = data.organization_id ?? ''
    }

    watch(() => editData, (val) => (val ? hydrate(val) : form.reset()), { immediate: true })

    // Keep the logic inside the composable
    const addSchemaItem = () => {
        form.document_schema.push({ label: '', key: '', is_task: false })
    }

    const addWorkflowStep = () => {
        form.workflow.push({
            step: form.workflow.length + 1,
            from_key: '',
            to_key: '',
            ai_template_id: null
        })
    }

    const addLifecycleStep = () => {
        form.lifecycle_steps.push({
            order: form.lifecycle_steps.length + 1,
            label: '',
            description: '',
            color: 'indigo',
        })
    }

    const submit = () => {
        // Optional: Filter out empty workflow steps if you want to allow "blank" rows in UI
        // form.workflow = form.workflow.filter(w => w.from_key && w.to_key);

        const url = editData
            ? projectTypeRoutes.update.url(editData.id)
            : projectTypeRoutes.store.url();

        // Use the method name dynamically
        const method = editData ? 'put' : 'post';

        form[method](url, {
            preserveScroll: true,
            onSuccess: () => onSuccess(),
            onError: (errors) => {
                const firstError = Object.values(errors)[0]
                toast.error(firstError ?? 'Failed to save protocol. Please check the form and try again.')
            }
        });
    };

    const suggestKey = (index: number) => {
        const doc = form.document_schema[index]
        if (doc.key === 'intake' || !doc.label) return
        doc.key = doc.label.toLowerCase().trim().replace(/[^a-z0-9 ]/g, '').replace(/\s+/g, '_')
    }



    return { form, submit, addSchemaItem, addWorkflowStep, suggestKey, addLifecycleStep }
}
