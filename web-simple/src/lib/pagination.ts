export const PAGINATION_PAGE_SIZE = 25;

export function parsePage(value?: string) {
  const page = Number(value);
  return Number.isSafeInteger(page) && page > 0 ? page : 1;
}

export function getPageCount(total: number, pageSize = PAGINATION_PAGE_SIZE) {
  return Math.max(1, Math.ceil(total / pageSize));
}
