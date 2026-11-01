<template>
  <div class="space-y-3">
    <!-- Size: steppers for exact changes, a grid to pick a new size at a glance -->
    <div class="flex items-center gap-2">
      <div v-for="axis in axes" :key="axis.name" class="flex min-w-0 flex-1 items-center justify-between gap-1 rounded-lg border border-slate-200 px-1.5 py-1">
        <span class="truncate text-xs text-slate-500">{{ t(axis.label) }}</span>
        <div class="flex items-center">
          <button type="button" class="rounded p-0.5 text-slate-500 hover:bg-slate-100 disabled:opacity-30" :disabled="structuralLocked || axis.count <= 1" :aria-label="t(axis.remove)" @click="setSize(axis.name, axis.count - 1)"><Icon icon="mdi:minus" class="h-3.5 w-3.5" /></button>
          <span class="w-6 text-center text-xs font-semibold tabular-nums text-slate-700">{{ axis.count }}</span>
          <button type="button" class="rounded p-0.5 text-slate-500 hover:bg-slate-100 disabled:opacity-30" :disabled="structuralLocked || axis.count >= axis.max" :aria-label="t(axis.add)" @click="setSize(axis.name, axis.count + 1)"><Icon icon="mdi:plus" class="h-3.5 w-3.5" /></button>
        </div>
      </div>
      <button type="button" class="rounded-lg border border-slate-200 p-1.5 text-slate-500 hover:bg-slate-50 disabled:opacity-30" :disabled="structuralLocked" :aria-expanded="picking" :title="t('site.table.pickSize')" :aria-label="t('site.table.pickSize')" @click="picking = !picking"><Icon icon="mdi:table-large" class="h-4 w-4" /></button>
    </div>
    <div v-if="picking" class="rounded-lg border border-slate-200 p-2" @mouseleave="hover = null">
      <div class="grid gap-0.5" :style="{ gridTemplateColumns: `repeat(${PICKER_COLUMNS}, minmax(0, 1fr))` }" role="grid" :aria-label="t('site.table.pickSize')">
        <template v-for="r in PICKER_ROWS" :key="r">
          <button
            v-for="c in PICKER_COLUMNS"
            :key="c"
            type="button"
            class="aspect-square rounded-sm border"
            :class="inPicked(r, c) ? 'border-theme-500 bg-theme-100' : 'border-slate-200 bg-white'"
            :aria-label="t('site.table.sizeChoice', { rows: r, columns: c })"
            @mouseenter="hover = { rows: r, columns: c }"
            @focus="hover = { rows: r, columns: c }"
            @click="pick(r, c)"
          ></button>
        </template>
      </div>
      <p class="mt-1.5 text-center text-xs text-slate-500">{{ t('site.table.size', picked) }}</p>
    </div>

    <!-- A map of the cells: pick one to edit it and act on its row and column -->
    <div class="overflow-x-auto rounded-lg border border-slate-200">
      <table class="w-full border-collapse text-[11px]">
        <thead>
          <tr>
            <th class="w-6 bg-slate-50"></th>
            <th v-for="(_, c) in columnCount" :key="c" class="min-w-[3.5rem] border-l border-slate-200 bg-slate-50 px-1 py-0.5 font-semibold text-slate-500" :class="{ 'text-theme-700': cell?.column === c }">{{ columnName(c) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, r) in rows" :key="r" class="border-t border-slate-200">
            <th class="bg-slate-50 px-1 font-semibold text-slate-500" :class="{ 'text-theme-700': cell?.row === r }">{{ r + 1 }}</th>
            <td v-for="(value, c) in row" :key="c" class="border-l border-slate-200 p-0">
              <button
                type="button"
                class="block min-h-[1.625rem] w-full max-w-[7rem] truncate px-1 py-1 text-left"
                :class="[cell?.row === r && cell?.column === c ? 'bg-theme-100 text-theme-900 ring-1 ring-inset ring-theme-500' : 'text-slate-700 hover:bg-slate-50', headerCell(r, c) ? 'font-semibold' : '']"
                :aria-pressed="cell?.row === r && cell?.column === c"
                :aria-label="`${cellName(r, c)}: ${summary(value) || t('site.table.emptyCell')}`"
                @click="selectCell(r, c)"
              >{{ summary(value) }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="!cell" class="text-xs text-slate-400">{{ t('site.table.selectHint') }}</p>

    <div v-else class="space-y-2 rounded-lg border border-slate-200 p-2">
      <div v-for="group in cellActions" :key="group.name" class="flex items-center gap-1">
        <span class="mr-auto text-xs font-semibold text-slate-600">{{ group.title }}</span>
        <button
          v-for="action in group.actions"
          :key="action.name"
          type="button"
          class="rounded p-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30"
          :disabled="structuralLocked || action.disabled"
          :title="t(action.label)"
          :aria-label="`${t(action.label)}: ${group.title}`"
          @click="apply(action.run)"
        ><Icon :icon="action.icon" class="h-4 w-4" /></button>
      </div>
      <SmartValueEditor :key="`${cell.row}:${cell.column}`" :model-value="rows[cell.row]?.[cell.column] ?? ''" :node="node" target="html" :label="t('site.table.cell', { name: cellName(cell.row, cell.column) })" @update:model-value="updateCell" />
    </div>

    <details class="rounded-lg border border-slate-200" :open="pasting" @toggle="pasting = $event.target.open">
      <summary class="cursor-pointer px-2 py-1.5 text-xs font-semibold text-slate-600">{{ t('site.table.paste') }}</summary>
      <div class="space-y-2 p-2 pt-0">
        <label class="sr-only" :for="`${id}-paste`">{{ t('site.table.paste') }}</label>
        <textarea :id="`${id}-paste`" :value="pasted" rows="4" class="form-textarea w-full rounded-lg border-slate-300 font-mono text-xs" :placeholder="t('site.table.pastePlaceholder')" :disabled="structuralLocked" @input="pasted = $event.target.value"></textarea>
        <button type="button" class="btn-secondary btn-sm w-full justify-center" :disabled="structuralLocked || !pasted.trim()" @click="replaceFromPaste"><Icon icon="mdi:table-arrow-down" class="h-4 w-4" />{{ t('site.table.replace') }}</button>
      </div>
    </details>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Icon } from '@iconify/vue';
import SmartValueEditor from './SmartValueEditor.vue';
import { useSiteBuilder } from '../../../composables/useSiteBuilder.js';
import { useI18n } from '../../../i18n/index.js';
import { smartSummary } from '../../../composables/smartValues.js';
import {
  MAX_COLUMNS, MAX_ROWS, cellName, columnName, duplicateColumn, duplicateRow, insertColumn, insertRow,
  moveColumn, moveRow, parseTsv, removeColumn, removeRow, resize, setCell,
} from '../../../composables/tableGrid.js';

const PICKER_ROWS = 8;
const PICKER_COLUMNS = 10;

const props = defineProps({ id: { type: String, required: true }, node: { type: Object, required: true }, modelValue: { type: Array, default: () => [] } });
const emit = defineEmits(['update:modelValue']);
const builder = useSiteBuilder();
const { t } = useI18n();

const rows = computed(() => (Array.isArray(props.modelValue) && props.modelValue.length ? props.modelValue : [['']]));
const columnCount = computed(() => rows.value[0]?.length ?? 1);
// Translations change the texts; rows and columns belong to the shared design.
const structuralLocked = computed(() => !!builder.translating?.value);
const picking = ref(false);
const hover = ref(null);
const pasting = ref(false);
const pasted = ref('');

const cell = computed(() => {
  const selected = builder.selectedCell?.value;
  if (selected?.id !== props.node.id || selected.row >= rows.value.length || selected.column >= columnCount.value) return null;
  return selected;
});

const axes = computed(() => [
  { name: 'rows', label: 'site.table.rows', count: rows.value.length, max: MAX_ROWS, add: 'site.table.addRow', remove: 'site.table.removeLastRow' },
  { name: 'columns', label: 'site.table.columns', count: columnCount.value, max: MAX_COLUMNS, add: 'site.table.addColumn', remove: 'site.table.removeLastColumn' },
]);
const picked = computed(() => hover.value ?? { rows: Math.min(rows.value.length, PICKER_ROWS), columns: Math.min(columnCount.value, PICKER_COLUMNS) });
const inPicked = (r, c) => r <= picked.value.rows && c <= picked.value.columns;

const summary = (value) => (typeof value === 'string' ? value.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').trim() : smartSummary(value));
const headerCell = (r, c) => (r === 0 && props.node.props?.header_row !== false) || (c === 0 && !!props.node.props?.header_column);

const cellActions = computed(() => {
  const { row, column } = cell.value;
  const last = { row: rows.value.length - 1, column: columnCount.value - 1 };
  return [
    {
      name: 'row', title: t('site.table.row', { number: row + 1 }),
      actions: [
        { name: 'above', icon: 'mdi:table-row-plus-before', label: 'site.table.insertAbove', run: () => [insertRow(rows.value, row), row + 1, column], disabled: rows.value.length >= MAX_ROWS },
        { name: 'below', icon: 'mdi:table-row-plus-after', label: 'site.table.insertBelow', run: () => [insertRow(rows.value, row + 1), row, column], disabled: rows.value.length >= MAX_ROWS },
        { name: 'duplicate', icon: 'mdi:content-copy', label: 'site.builder.duplicate', run: () => [duplicateRow(rows.value, row), row + 1, column], disabled: rows.value.length >= MAX_ROWS },
        { name: 'up', icon: 'mdi:arrow-up', label: 'site.builder.moveUp', run: () => [moveRow(rows.value, row, row - 1), row - 1, column], disabled: row === 0 },
        { name: 'down', icon: 'mdi:arrow-down', label: 'site.builder.moveDown', run: () => [moveRow(rows.value, row, row + 1), row + 1, column], disabled: row === last.row },
        { name: 'delete', icon: 'mdi:table-row-remove', label: 'site.table.deleteRow', run: () => [removeRow(rows.value, row), Math.min(row, last.row - 1), column], disabled: rows.value.length <= 1 },
      ],
    },
    {
      name: 'column', title: t('site.table.column', { name: columnName(column) }),
      actions: [
        { name: 'left', icon: 'mdi:table-column-plus-before', label: 'site.table.insertLeft', run: () => [insertColumn(rows.value, column), row, column + 1], disabled: columnCount.value >= MAX_COLUMNS },
        { name: 'right', icon: 'mdi:table-column-plus-after', label: 'site.table.insertRight', run: () => [insertColumn(rows.value, column + 1), row, column], disabled: columnCount.value >= MAX_COLUMNS },
        { name: 'duplicate', icon: 'mdi:content-copy', label: 'site.builder.duplicate', run: () => [duplicateColumn(rows.value, column), row, column + 1], disabled: columnCount.value >= MAX_COLUMNS },
        { name: 'left-move', icon: 'mdi:arrow-left', label: 'site.table.moveLeft', run: () => [moveColumn(rows.value, column, column - 1), row, column - 1], disabled: column === 0 },
        { name: 'right-move', icon: 'mdi:arrow-right', label: 'site.table.moveRight', run: () => [moveColumn(rows.value, column, column + 1), row, column + 1], disabled: column === last.column },
        { name: 'delete', icon: 'mdi:table-column-remove', label: 'site.table.deleteColumn', run: () => [removeColumn(rows.value, column), row, Math.min(column, last.column - 1)], disabled: columnCount.value <= 1 },
      ],
    },
  ];
});

function selectCell(row, column) {
  if (builder.selectedCell) builder.selectedCell.value = { id: props.node.id, row, column };
}

/** Runs a row or column action; the selection follows the cell it was on. */
function apply(run) {
  const [next, row, column] = run();
  emit('update:modelValue', next);
  selectCell(row, column);
}

function setSize(axis, count) {
  emit('update:modelValue', axis === 'rows' ? resize(rows.value, count, columnCount.value) : resize(rows.value, rows.value.length, count));
}

function pick(rowCount, columnCount) {
  emit('update:modelValue', resize(rows.value, rowCount, columnCount));
  picking.value = false;
}

function updateCell(value) {
  emit('update:modelValue', setCell(rows.value, cell.value.row, cell.value.column, value));
}

function replaceFromPaste() {
  emit('update:modelValue', parseTsv(pasted.value));
  pasted.value = '';
  pasting.value = false;
}
</script>
