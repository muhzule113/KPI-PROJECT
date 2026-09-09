import os from "node:os";

try {
  os.userInfo();
} catch (error) {
  if (error?.syscall !== "uv_os_get_passwd") throw error;
  os.userInfo = () => ({
    uid: -1,
    gid: -1,
    username: process.env.USERNAME ?? "user",
    homedir: process.env.USERPROFILE ?? os.homedir(),
    shell: null,
  });
}
