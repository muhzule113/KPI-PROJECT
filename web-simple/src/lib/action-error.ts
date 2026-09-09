export function actionError(error: unknown, fallback: string) {
  return error instanceof Error && error.constructor === Error && !("code" in error) ? error.message : fallback;
}
