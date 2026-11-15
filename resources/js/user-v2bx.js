/* Inserted into the original Vue theme module. Uses its existing axios,
 * Naive UI, Vue lifecycle and authenticated session; no extra application.
 */
const XBUserConnections = {
  name: "SubscriptionConnections",
  setup() {
    const rows = Et([]), error = Et(""), busy = Et(false);
    const load = async () => {
      busy.value = true;
      try {
        const response = await EN.get("/user/server/connections");
        if (!response.data) throw Error(response.message || "无法读取连接配置");
        rows.value = response.data.nodes.map(r => ({...r, kind: "node"}));
        error.value = "";
      } catch (e) {error.value = e.message || "无法读取连接配置";} finally {busy.value = false;}
    };
    const download = async row => {
      busy.value = true;
      try {
        const response = await EN.post("/user/server/export", {kind: "node", id: row.id});
        if (response?.code && response?.message) throw Error(response.message);
        const text = typeof response === "string" ? response : JSON.stringify(response, null, 2);
        const link = document.createElement("a"), url = URL.createObjectURL(new Blob([text], {type: "application/octet-stream"}));
        link.href = url; link.download = "connection-" + row.id + ".json"; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000); error.value = "";
      } catch (e) {error.value = e.message || "配置导出失败";} finally {busy.value = false;}
    };
    const rotate = async () => {
      if (!window.confirm("轮换后旧连接密码将失效，是否继续？")) return;
      busy.value = true;
      try {
        const response = await EN.post("/user/server/rotateCredentials", {});
        if (!response.data) throw Error(response.message || "密码轮换失败");
        window.$message.success("连接密码已轮换，请重新下载配置");
      } catch (e) {error.value = e.message;} finally {busy.value = false;}
    };
    Dn(load);
    const button = (text, onClick, props = {}) => Lr(oO, {size: "small", disabled: busy.value, onClick, ...props}, {default: () => text});
    return () => Lr("div", {class: "mt-5 border-t pt-4", "data-native-subscription-connections": ""}, [
      Lr("div", {class: "mb-3 flex flex-wrap items-center justify-between gap-2"}, [Lr("span", {class: "text-base"}, "连接配置"),
        Lr("div", {class: "flex gap-2"}, [button("刷新", load), button("轮换连接密码", rotate)])]),
      error.value && Lr("p", {role: "alert", class: "mb-3 text-sm text-red-500"}, error.value),
      !rows.value.length ? Lr("p", {class: "text-sm text-gray-500"}, busy.value ? "正在读取连接…" : "暂无可用的连接配置") :
        Lr("div", {class: "space-y-3"}, rows.value.map(row => Lr("div", {key: row.kind + row.id, class: "flex flex-wrap items-center justify-between gap-3"}, [
          Lr("div", null, [Lr("p", null, row.name), Lr("p", {class: "text-sm text-gray-500"}, row.type + (row.addresses ? " · " + row.addresses.join(", ") : ""))]),
          button("下载 V2bX 配置", () => download(row))]))),
      Lr("p", {class: "mt-3 text-xs text-gray-500"}, "高级 VLESS/XDRIVE/MASQUE/HY2 节点会下载 Xray JSON；普通节点继续使用原订阅模板。")
    ]);
  }
};
