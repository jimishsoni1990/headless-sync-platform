/**
 * HSP Delivery API client.
 *
 * Consumes the REST Delivery API contract only.
 * No WordPress reads. No direct PostgreSQL access. (ADR-040)
 *
 * Base URL is configured via HSP_API_BASE_URL environment variable.
 * Default: http://headless-sync-platform.local
 */

const API_BASE = (process.env.HSP_API_BASE_URL ?? 'http://headless-sync-platform.local').replace(
  /\/+$/,
  '',
);

// ---------------------------------------------------------------------------
// Response shapes (API contract — DECISION F / P1A-S5)
// ---------------------------------------------------------------------------

export interface Post {
  slug: string;
  title: string;
  content: string;
  excerpt: string;
  status: string;
  author: string;
  published_at: string | null;
  updated_at: string | null;
  meta: Record<string, unknown>;
}

export interface Page {
  slug: string;
  title: string;
  content: string;
  status: string;
  parent_id: number;
  menu_order: number;
  published_at: string | null;
  updated_at: string | null;
  meta: Record<string, unknown>;
}

export interface Category {
  slug: string;
  name: string;
  description: string;
  parent_id: number;
  post_count: number;
}

export interface ListResponse<T> {
  data: T[];
  next_cursor: string | null;
}

// ---------------------------------------------------------------------------
// Fetch helpers
// ---------------------------------------------------------------------------

async function apiFetch<T>(path: string): Promise<T | null> {
  const url = `${API_BASE}/wp-json/${path}`;
  const res = await fetch(url, { next: { revalidate: 60 } });

  if (res.status === 404) return null;
  if (!res.ok) throw new Error(`HSP API error ${res.status} at ${url}`);

  return (await res.json()) as T;
}

// ---------------------------------------------------------------------------
// Posts
// ---------------------------------------------------------------------------

export async function getPosts(params?: {
  cursor?: string;
  per_page?: number;
  category?: string;
  published_after?: string;
}): Promise<ListResponse<Post>> {
  const qs = new URLSearchParams();
  if (params?.cursor) qs.set('cursor', params.cursor);
  if (params?.per_page) qs.set('per_page', String(params.per_page));
  if (params?.category) qs.set('category', params.category);
  if (params?.published_after) qs.set('published_after', params.published_after);

  const query = qs.toString() ? `?${qs.toString()}` : '';
  const result = await apiFetch<ListResponse<Post>>(`hsp/v1/posts${query}`);
  return result ?? { data: [], next_cursor: null };
}

export async function getPost(slug: string): Promise<Post | null> {
  return apiFetch<Post>(`hsp/v1/posts/${slug}`);
}

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

export async function getPages(params?: {
  cursor?: string;
  per_page?: number;
}): Promise<ListResponse<Page>> {
  const qs = new URLSearchParams();
  if (params?.cursor) qs.set('cursor', params.cursor);
  if (params?.per_page) qs.set('per_page', String(params.per_page));

  const query = qs.toString() ? `?${qs.toString()}` : '';
  const result = await apiFetch<ListResponse<Page>>(`hsp/v1/pages${query}`);
  return result ?? { data: [], next_cursor: null };
}

export async function getPage(slug: string): Promise<Page | null> {
  return apiFetch<Page>(`hsp/v1/pages/${slug}`);
}

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------

export async function getCategories(params?: {
  cursor?: string;
  per_page?: number;
}): Promise<ListResponse<Category>> {
  const qs = new URLSearchParams();
  if (params?.cursor) qs.set('cursor', params.cursor);
  if (params?.per_page) qs.set('per_page', String(params.per_page));

  const query = qs.toString() ? `?${qs.toString()}` : '';
  const result = await apiFetch<ListResponse<Category>>(`hsp/v1/categories${query}`);
  return result ?? { data: [], next_cursor: null };
}

export async function getCategory(slug: string): Promise<Category | null> {
  return apiFetch<Category>(`hsp/v1/categories/${slug}`);
}
