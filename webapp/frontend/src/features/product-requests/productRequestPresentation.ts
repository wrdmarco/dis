import type {
  ProductRequest,
  ProductRequestStatus,
  ProductRequestType,
} from '../../types/api';

export type ProductRequestTab = 'all' | 'mine' | 'handling';

export interface ProductRequestFilters {
  tab: ProductRequestTab;
  type: ProductRequestType | 'all';
  status: ProductRequestStatus | 'all';
  query: string;
  userId: string;
}

export interface ProductRequestQuery {
  tab: ProductRequestTab;
  type: ProductRequestType | 'all';
  status: ProductRequestStatus | 'all';
  query: string;
  page: number;
  perPage?: number;
}

export const productRequestTypeOptions: ReadonlyArray<{
  value: ProductRequestType;
  label: string;
}> = [
  { value: 'bug', label: 'Bug' },
  { value: 'change', label: 'Aanpassing' },
  { value: 'feature', label: 'Feature' },
];

export const productRequestStatusOptions: ReadonlyArray<{
  value: ProductRequestStatus;
  label: string;
}> = [
  { value: 'open', label: 'Open' },
  { value: 'in_progress', label: 'In behandeling' },
  { value: 'resolved', label: 'Opgelost' },
  { value: 'rejected', label: 'Afgewezen' },
];

const handlingStatuses = new Set<ProductRequestStatus>(['open', 'in_progress']);

export function buildProductRequestsPath(query: ProductRequestQuery): string {
  const parameters = new URLSearchParams();
  if (query.tab === 'mine') {
    parameters.set('mine', '1');
  }
  if (query.type !== 'all') {
    parameters.set('type', query.type);
  }
  if (query.status !== 'all') {
    parameters.set('status', query.status);
  } else if (query.tab === 'handling') {
    parameters.set('status', 'open,in_progress');
  }

  const normalizedSearch = query.query.trim().replace(/\s+/g, ' ');
  if (normalizedSearch !== '') {
    parameters.set('search', normalizedSearch);
  }

  parameters.set('page', String(Math.max(1, Math.floor(query.page))));
  parameters.set('per_page', String(query.perPage ?? 25));

  return `/product-requests?${parameters.toString()}`;
}

export function filterProductRequests(
  requests: readonly ProductRequest[],
  filters: ProductRequestFilters,
): ProductRequest[] {
  const normalizedQuery = filters.query.trim().toLocaleLowerCase('nl-NL');

  return requests
    .filter((request) => {
      if (filters.tab === 'mine' && request.requester.id !== filters.userId) {
        return false;
      }
      if (filters.tab === 'handling' && !handlingStatuses.has(request.status)) {
        return false;
      }
      if (filters.type !== 'all' && request.type !== filters.type) {
        return false;
      }
      if (filters.status !== 'all' && request.status !== filters.status) {
        return false;
      }
      if (normalizedQuery === '') {
        return true;
      }

      return [
        request.title,
        request.description,
        request.requester.name ?? '',
        request.resolution_note ?? '',
      ]
        .join(' ')
        .toLocaleLowerCase('nl-NL')
        .includes(normalizedQuery);
    })
    .sort((left, right) => Date.parse(right.updated_at) - Date.parse(left.updated_at));
}

export function productRequestTypeLabel(type: ProductRequestType): string {
  return productRequestTypeOptions.find((option) => option.value === type)?.label ?? type;
}

export function productRequestStatusLabel(status: ProductRequestStatus): string {
  return productRequestStatusOptions.find((option) => option.value === status)?.label ?? status;
}

export function productRequestStatusTone(
  status: ProductRequestStatus,
): 'neutral' | 'info' | 'good' | 'bad' {
  switch (status) {
    case 'in_progress':
      return 'info';
    case 'resolved':
      return 'good';
    case 'rejected':
      return 'bad';
    default:
      return 'neutral';
  }
}

export function allowedProductRequestTransitions(
  currentStatus: ProductRequestStatus,
): ProductRequestStatus[] {
  switch (currentStatus) {
    case 'open':
      return ['in_progress', 'resolved', 'rejected'];
    case 'in_progress':
      return ['open', 'resolved', 'rejected'];
    case 'resolved':
    case 'rejected':
      return ['open'];
  }
}
