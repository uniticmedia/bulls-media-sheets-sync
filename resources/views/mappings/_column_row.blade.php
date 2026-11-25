<tr>
    <td>
        <input type="text" name="columns[{{ $i }}][source_column]" value="{{ $column['source_column'] ?? '' }}"
               class="form-control form-control-sm" placeholder="e.g. product_name" maxlength="190" required>
    </td>
    <td>
        <input type="text" name="columns[{{ $i }}][target_column]" value="{{ $column['target_column'] ?? '' }}"
               class="form-control form-control-sm mono" placeholder="e.g. name" maxlength="63"
               pattern="[a-z][a-z0-9_]*" title="lowercase letters, digits and underscores, starting with a letter" required>
    </td>
    <td>
        <select name="columns[{{ $i }}][data_type]" class="form-select form-select-sm">
            @foreach ($types as $type)
                <option value="{{ $type }}" @selected(($column['data_type'] ?? 'string') === $type)>{{ $type }}</option>
            @endforeach
        </select>
    </td>
    <td class="text-end">
        <button type="button" class="btn btn-sm btn-outline-danger remove-column" title="Remove this column" aria-label="Remove this column">&times;</button>
    </td>
</tr>