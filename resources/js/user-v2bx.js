/* Inserted into the original Vue theme module. Uses its existing axios,
 * Naive UI, Vue lifecycle and authenticated session; no extra application.
 */
const XBUserConnections = {
  name: "SubscriptionConnections",
  setup() {
    const rows = Et([]), error = Et(""), busy = Et(false), selected = Et(null), privateKey = Et("");
    const load = async () => {
      busy.value = true;
      try {
        const response = await EN.get("/user/server/connections");
        if (!response.data) throw Error(response.message || "无法读取连接配置");
        rows.value = [...response.data.nodes.map(r => ({...r, kind: "node"})), ...response.data.services.map(r => ({...r, kind: "service"}))];
        error.value = "";
      } catch (e) {error.value = e.message || "无法读取连接配置";} finally {busy.value = false;}
    };
    const close = () => {privateKey.value = ""; selected.value = null;};
    const download = async row => {
      busy.value = true;
      try {
        const response = await EN.post("/user/server/export", {kind: row.kind, id: row.id, ...(row.kind === "service" ? {private_key: privateKey.value} : {})});
        if (response?.code && response?.message) throw Error(response.message);
        const text = typeof response === "string" ? response : JSON.stringify(response, null, 2);
        const link = document.createElement("a"), url = URL.createObjectURL(new Blob([text], {type: "application/octet-stream"}));
        link.href = url; link.download = "connection-" + row.id + (row.kind === "service" ? ".conf" : ".json"); link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000); close(); error.value = "";
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
    Dn(load); Hn(close);
    const button = (text, onClick, props = {}) => Lr(oO, {size: "small", disabled: busy.value, onClick, ...props}, {default: () => text});
    return () => Lr("div", {class: "mt-5 border-t pt-4", "data-native-subscription-connections": ""}, [
      Lr("div", {class: "mb-3 flex flex-wrap items-center justify-between gap-2"}, [Lr("span", {class: "text-base"}, "连接配置"),
        Lr("div", {class: "flex gap-2"}, [button("刷新", load), button("轮换连接密码", rotate)])]),
      error.value && Lr("p", {role: "alert", class: "mb-3 text-sm text-red-500"}, error.value),
      !rows.value.length ? Lr("p", {class: "text-sm text-gray-500"}, busy.value ? "正在读取连接…" : "暂无可用的连接配置") :
        Lr("div", {class: "space-y-3"}, rows.value.map(row => Lr("div", {key: row.kind + row.id, class: "flex flex-wrap items-center justify-between gap-3"}, [
          Lr("div", null, [Lr("p", null, row.name), Lr("p", {class: "text-sm text-gray-500"}, row.type + (row.addresses ? " · " + row.addresses.join(", ") : ""))]),
          button("下载配置", () => row.kind === "node" ? download(row) : (privateKey.value = "", selected.value = row))]))),
      Lr(RI, {show: !!selected.value, "onUpdate:show": show => {if (!show) close();}, preset: "card", title: "下载客户端配置", class: "mx-2.5 max-w-full w-150 md:mx-auto"}, {
        default: () => [Lr("label", {for: "native-client-key", class: "mb-2 block text-sm"}, "客户端私钥"),
          Lr("input", {id: "native-client-key", type: "password", autocomplete: "off", value: privateKey.value,
            class: "w-full rounded-md border bg-transparent p-2", onInput: e => {privateKey.value = e.target.value;}}),
          Lr("p", {class: "mt-2 text-sm text-gray-500"}, "需要与已登记的客户端公钥匹配，面板不保存客户端私钥。")],
        footer: () => Lr("div", {class: "flex justify-end gap-2"}, [button("取消", close), button("下载配置", () => download(selected.value), {type: "primary"})])})
    ]);
  }
};
