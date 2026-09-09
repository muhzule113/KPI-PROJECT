export const USERNAME_MIN_LENGTH = 3;
export const USERNAME_MAX_LENGTH = 50;
export const USERNAME_INPUT_PATTERN = "[A-Za-z0-9](?:[A-Za-z0-9._]|-){1,48}[A-Za-z0-9]";

const USERNAME_PATTERN = new RegExp(`^${USERNAME_INPUT_PATTERN}$`, "i");

export const normalizeUsername = (value: string) => value.trim().toLowerCase();

export const isValidUsername = (value: string) => USERNAME_PATTERN.test(normalizeUsername(value));
