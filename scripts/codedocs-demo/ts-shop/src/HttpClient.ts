/** Small typed HTTP client used by the shop API functions. */
export const apiClient = {
  /** Read a JSON resource from the backend. */
  async get<T>(path: string): Promise<T> {
    const response = await fetch(path);
    if (!response.ok) throw new Error('Request failed');
    return response.json();
  },
  /** Create a JSON resource on the backend. */
  async post<T>(path: string, body: unknown): Promise<T> {
    const response = await fetch(path, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error('Request failed');
    return response.json();
  },
};
