<template>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <div>
      <label class="text-xs text-slate-500 block mb-1">Minimum</label>
      <input
        v-model.number="field.min"
        type="number"
        step="any"
        class="form-input w-full rounded-lg border-slate-300 text-sm"
        @input="normalize"
      />
    </div>

    <div>
      <label class="text-xs text-slate-500 block mb-1">Step</label>
      <input
        v-model.number="field.step"
        type="number"
        step="any"
        min="0"
        class="form-input w-full rounded-lg border-slate-300 text-sm"
        @input="normalize"
      />
    </div>

    <div>
      <label class="text-xs text-slate-500 block mb-1">Maximum</label>
      <input
        v-model.number="field.max"
        type="number"
        step="any"
        class="form-input w-full rounded-lg border-slate-300 text-sm"
        @input="normalize"
      />
    </div>

    <div>
      <label class="text-xs text-slate-500 block mb-1">Decimals</label>
      <select
        v-model="field.display_decimals"
        class="form-select w-full rounded-lg border-slate-300 text-sm"
        @change="normalize"
      >
        <option :value="0">0</option>
        <option :value="1">1</option>
        <option :value="2">2</option>
        <option :value="3">3</option>
        <option value="full">Full</option>
      </select>
    </div>
  </div>
</template>

<script setup>
import { rangeDefaults } from "../composables/fieldBuilderUtils.js";

const props = defineProps({
  field: { type: Object, required: true },
});

function normalize() {
  Object.assign(props.field, rangeDefaults(props.field));
}
</script>
