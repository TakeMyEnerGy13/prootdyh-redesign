// Accessible disclosure menu, sharing the CSS breakpoint.
(() => {
 const header=document.getElementById('topbar');
 const toggle=header.querySelector('.burger');
 const menu=header.querySelector('.nav');
 const mobile=matchMedia('(max-width:1180px)');
 function setOpen(open){header.classList.toggle('menu-open',open);toggle.setAttribute('aria-expanded',String(open));toggle.setAttribute('aria-label',open?'Закрыть меню':'Открыть меню');}
 toggle.addEventListener('click',()=>setOpen(toggle.getAttribute('aria-expanded')!=='true'));
 header.addEventListener('keydown',event=>{if(event.key==='Escape'&&toggle.getAttribute('aria-expanded')==='true'){setOpen(false);toggle.focus();}});
 menu.addEventListener('click',event=>{if(event.target.closest('a'))setOpen(false);});
 document.addEventListener('click',event=>{if(!header.contains(event.target))setOpen(false);});
 mobile.addEventListener('change',()=>setOpen(false));
})();
// Self-contained WebGL scene. No runtime dependencies.
(() => {
  const canvas = document.getElementById('travel-scene');
  const gl = canvas.getContext('webgl', {alpha:true,antialias:true,premultipliedAlpha:false});
  const fallback = document.querySelector('.planet-fallback');
  if (!gl) { canvas.hidden=true; fallback.hidden=false; return; }
  const vertex = `attribute vec3 position; attribute vec3 normal; attribute vec2 uv;
    uniform mat4 model; uniform float aspect; varying vec3 n; varying vec3 world; varying vec2 tex;
    void main(){vec4 p=model*vec4(position,1.); world=p.xyz; n=normalize(mat3(model)*normal); tex=uv;
    gl_Position=vec4(p.x/(3.*aspect),p.y/3.,-p.z/12.,1.);}`;
  const fragment = `precision mediump float; varying vec3 n; varying vec3 world; varying vec2 tex;
    uniform vec3 color; uniform sampler2D surface; uniform float textured;
    void main(){if(textured<-.5){gl_FragColor=vec4(color,tex.x);return;}vec3 N=normalize(n); vec3 L=normalize(vec3(-.7,1.,1.6));
    float diffuse=max(dot(N,L),0.); vec3 base=color;
    if(textured>.5){base=texture2D(surface,tex).rgb; base=mix(base,vec3(.69,.52,.72),.13);}
    vec3 lit=base*(textured>.5?(.36+.77*diffuse):(.48+.6*diffuse));
    float spec=pow(max(dot(reflect(-L,N),vec3(0.,0.,1.)),0.),38.);
    lit+=vec3(1.,.97,.94)*spec*(textured>.5?.13:.27);
    if(textured>.5){float rim=pow(1.-max(N.z,0.),3.);lit=mix(lit,vec3(.75,.62,.87),rim*.55);}
    gl_FragColor=vec4(lit,1.);}`;
  function shader(type,source){const s=gl.createShader(type);gl.shaderSource(s,source);gl.compileShader(s);if(!gl.getShaderParameter(s,gl.COMPILE_STATUS))throw Error(gl.getShaderInfoLog(s));return s;}
  const program=gl.createProgram();gl.attachShader(program,shader(gl.VERTEX_SHADER,vertex));gl.attachShader(program,shader(gl.FRAGMENT_SHADER,fragment));gl.linkProgram(program);
  if(!gl.getProgramParameter(program,gl.LINK_STATUS))throw Error(gl.getProgramInfoLog(program));gl.useProgram(program);
  const attrs=Object.fromEntries(['position','normal','uv'].map(x=>[x,gl.getAttribLocation(program,x)]));
  const uniforms=Object.fromEntries(['model','aspect','color','textured','surface'].map(x=>[x,gl.getUniformLocation(program,x)]));
  const I=()=>[1,0,0,0,0,1,0,0,0,0,1,0,0,0,0,1];
  function mul(a,b){const o=Array(16).fill(0);for(let c=0;c<4;c++)for(let r=0;r<4;r++)for(let k=0;k<4;k++)o[c*4+r]+=a[k*4+r]*b[c*4+k];return o;}
  function T(x,y,z){const m=I();m[12]=x;m[13]=y;m[14]=z;return m;}
  const S=(x,y=x,z=x)=>[x,0,0,0,0,y,0,0,0,0,z,0,0,0,0,1];
  function R(axis,a){const c=Math.cos(a),s=Math.sin(a);return axis==='x'?[1,0,0,0,0,c,s,0,0,-s,c,0,0,0,0,1]:axis==='y'?[c,0,-s,0,0,1,0,0,s,0,c,0,0,0,0,1]:[c,s,0,0,-s,c,0,0,0,0,1,0,0,0,0,1];}
  function mesh(vertices){const buffer=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,buffer);gl.bufferData(gl.ARRAY_BUFFER,new Float32Array(vertices),gl.STATIC_DRAW);return{buffer,count:vertices.length/8};}
  const vertices=[];
  function spherePoint(i,j){const lat=i/64*Math.PI,lon=j/128*Math.PI*2;const p=[Math.sin(lat)*Math.cos(lon),Math.cos(lat),Math.sin(lat)*Math.sin(lon)];return [...p,...p,1-j/128,i/64];}
  for(let i=0;i<64;i++)for(let j=0;j<128;j++){const a=spherePoint(i,j),b=spherePoint(i+1,j),c=spherePoint(i+1,j+1),d=spherePoint(i,j+1);vertices.push(...a,...b,...c,...a,...c,...d);}
  const sphere=mesh(vertices);
  const detailVertices=[];
  for(let i=0;i<12;i++)for(let j=0;j<20;j++){const a=spherePoint(i/12*64,j/20*128),b=spherePoint((i+1)/12*64,j/20*128),c=spherePoint((i+1)/12*64,(j+1)/20*128),d=spherePoint(i/12*64,(j+1)/20*128);detailVertices.push(...a,...b,...c,...a,...c,...d);}
  const detailSphere=mesh(detailVertices);
  function triangle(arr,a,b,c){const u=b.map((x,i)=>x-a[i]),v=c.map((x,i)=>x-a[i]);let n=[u[1]*v[2]-u[2]*v[1],u[2]*v[0]-u[0]*v[2],u[0]*v[1]-u[1]*v[0]];const l=Math.hypot(...n)||1;n=n.map(x=>x/l);for(const p of [a,b,c])arr.push(...p,...n,0,0);}
  function extrude(points,thickness,axis='y'){const a=[];const point=(p,h)=>axis==='y'?[p[0],h,p[1]]:[p[0],p[1],h];
    for(let i=1;i<points.length-1;i++){triangle(a,point(points[0],thickness),point(points[i+1],thickness),point(points[i],thickness));triangle(a,point(points[0],-thickness),point(points[i],-thickness),point(points[i+1],-thickness));}
    for(let i=0;i<points.length;i++){let p=points[i],q=points[(i+1)%points.length];triangle(a,point(p,-thickness),point(q,-thickness),point(q,thickness));triangle(a,point(p,-thickness),point(q,thickness),point(p,thickness));}return mesh(a);}
  // Smooth surfaces keep the silhouette continuous at close range.
  function profileAt(rings,x){
    let i=0;while(i<rings.length-2&&x>rings[i+1][0])i++;
    const u=Math.max(0,Math.min(1,(x-rings[i][0])/(rings[i+1][0]-rings[i][0])));
    const p0=rings[Math.max(0,i-1)][1],p1=rings[i][1],p2=rings[i+1][1],p3=rings[Math.min(rings.length-1,i+2)][1];
    return Math.max(.001,.5*((2*p1)+(-p0+p2)*u+(2*p0-5*p1+4*p2-p3)*u*u+(-p0+3*p1-3*p2+p3)*u*u*u));
  }
  function smoothSurface(rows,cols,point){
    const data=[];
    const vertex=(i,j)=>{const u=i/rows,v=j/cols,p=point(u,v),e=.0005;
      const a=point(Math.min(1,u+e),v),b=point(Math.max(0,u-e),v),c=point(u,v+e),d=point(u,v-e);
      const du=a.map((x,k)=>x-b[k]),dv=c.map((x,k)=>x-d[k]);
      let n=[dv[1]*du[2]-dv[2]*du[1],dv[2]*du[0]-dv[0]*du[2],dv[0]*du[1]-dv[1]*du[0]];
      const len=Math.hypot(...n)||1;return [...p,...n.map(x=>x/len),0,0];};
    for(let i=0;i<rows;i++)for(let j=0;j<cols;j++){const a=vertex(i,j),b=vertex(i+1,j),c=vertex(i+1,j+1),d=vertex(i,j+1);data.push(...a,...b,...c,...a,...c,...d);}return mesh(data);
  }
  const bodyProfile=[[-1.12,.006],[-1.02,.043],[-.87,.093],[-.65,.134],[-.4,.14],[.35,.14],[.56,.137],[.68,.12],[.77,.095],[.85,.063],[.9,.032],[.925,.003]];
  const fuselage=smoothSurface(100,48,(u,v)=>{const x=-1.12+2.045*u,r=profileAt(bodyProfile,x),a=v*2*Math.PI;return[x,r*Math.cos(a),r*Math.sin(a)];});
  // Cambered wing sections, dihedral and swept, tapered tips.
  function makeWing(side,tail=false){return smoothSurface(32,32,(u,v)=>{
    const span=tail?.43:1.09;
    const lead=tail?-.69-.31*u:.3-.69*u;
    const chord=tail?.34-.25*u:.71-.57*u;
    const a=v*2*Math.PI*side,along=(1-Math.cos(a))*.5;
    const height=(tail?.015:.038)*(1-.82*u)*Math.sin(a);
    const lift=(tail?.045:.08)*u*u;
    return [lead-chord*along,lift+.009*Math.sin(Math.PI*along)+height,side*(.075+span*u)];
  });}
  const wings=[makeWing(1),makeWing(-1)];
  const tails=[makeWing(1,true),makeWing(-1,true)];
  const winglet=extrude([[-.39,.065],[-.44,.28],[-.5,.31],[-.54,.07]],.011,'z');
  const fin=extrude([[-.6,.065],[-.86,.44],[-.99,.45],[-1.035,.06]],.014,'z');
  const pylon=extrude([[.16,-.015],[-.22,-.02],[-.18,-.145],[.12,-.145]],.027,'z');
  const white=[.91,.925,.94],wingColor=[.76,.79,.82],pink=[.65,.055,.25],dark=[.095,.13,.17];
  // Glazing follows the hull curvature, so no pane sinks into the fuselage.
  const cockpitVertices=[];
  function glassPane(corners,side){
    const vertex=(u,v)=>{
      const top=corners[0].map((n,i)=>n*(1-u)+corners[1][i]*u);
      const bottom=corners[3].map((n,i)=>n*(1-u)+corners[2][i]*u);
      const x=top[0]*(1-v)+bottom[0]*v,angle=top[1]*(1-v)+bottom[1]*v;
      const r=profileAt(bodyProfile,x)+.0035;
      return [x,r*Math.cos(angle),side*r*Math.sin(angle),0,Math.cos(angle),side*Math.sin(angle),0,0];
    };
    for(let i=0;i<8;i++)for(let j=0;j<6;j++){
      const a=vertex(i/8,j/6),b=vertex((i+1)/8,j/6),c=vertex((i+1)/8,(j+1)/6),d=vertex(i/8,(j+1)/6);
      cockpitVertices.push(...a,...b,...c,...a,...c,...d);
    }
  }
  for(const side of [-1,1]){
    glassPane([[.665,.08],[.807,.14],[.772,.77],[.661,.79]],side);
  }
  const cockpit=mesh(cockpitVertices);
  // Rounded nacelle, metallic inlet lip, recessed fan and spinner.
  const engineProfile=[[-.25,.049],[-.19,.08],[-.06,.114],[.11,.12],[.205,.111],[.235,.096]];
  const nacelle=smoothSurface(36,40,(u,v)=>{const x=-.25+.485*u,r=profileAt(engineProfile,x),a=v*2*Math.PI;return[x,r*Math.cos(a),r*Math.sin(a)];});
  const inlet=smoothSurface(12,40,(u,v)=>{const a=u*Math.PI,r=.088+.009*Math.cos(a),x=.235+.009*Math.sin(a),b=v*Math.PI*2;return[x,r*Math.cos(b),r*Math.sin(b)];});
  const fanVertices=[];
  for(let i=0;i<18;i++){const a=i/18*Math.PI*2;
    const p=(r,t,x)=>[x,r*Math.cos(t),r*Math.sin(t)];
    triangle(fanVertices,p(.018,a,.218),p(.078,a+.19,.21),p(.078,a+.32,.21));
    triangle(fanVertices,p(.018,a,.218),p(.078,a+.32,.21),p(.028,a+.38,.217));
  }
  const fan=mesh(fanVertices);
  function cabinPatch(arr,x,y,w,h,side,offset=.001){
    const point=(px,py)=>[px,py,side*(Math.sqrt(Math.max(.001,profileAt(bodyProfile,px)**2-py*py))+offset)];
    const corners=[point(x-w,y-h),point(x+w,y-h),point(x+w,y+h),point(x-w,y+h)];triangle(arr,corners[0],corners[1],corners[2]);triangle(arr,corners[0],corners[2],corners[3]);
  }
  const windowVertices=[],doorVertices=[],doorInnerVertices=[];
  for(const side of [-1,1]){
    for(let x=-.61;x<.59;x+=.051){if(Math.abs(x-.47)<.035||Math.abs(x+.57)<.035)continue;cabinPatch(windowVertices,x,.071,.0105,.014,side);}
    for(const x of [-.69,.51]){cabinPatch(doorVertices,x,.008,.026,.067,side);cabinPatch(doorInnerVertices,x,.008,.023,.062,side,.002);}
  }
  const windows=mesh(windowVertices),doors=mesh(doorVertices),doorInners=mesh(doorInnerVertices);
  const trail=mesh([]);
  function airflow(t,range,scale,time){
    const data=[];
    const at=(q,side)=>{const a=Math.atan2(3.05*Math.PI*Math.cos(Math.PI*q),range*2);const offset=side*.33*scale;
      return[-range+range*2*q-Math.sin(a)*offset,-3.2+3.05*Math.sin(Math.PI*q)+Math.cos(a)*offset,4.35];};
    for(const side of [-1,1])for(let i=0;i<28;i++){
      const q=t-.038-i*.005, q2=q-.005;
      const a=at(q,side),b=at(q2,side);
      const fade=(1-i/28)**2*.31;
      const w=.009+i*.0012;
      const drift=Math.sin(i*.45-time*1.7)*.015*(i/28);a[1]+=drift;b[1]+=drift;
      const put=(p,dy,alpha)=>data.push(p[0],p[1]+dy,p[2],0,0,1,alpha,0);
      put(a,-w,fade);put(a,w,fade);put(b,w,fade*.94);put(a,-w,fade);put(b,w,fade*.94);put(b,-w,fade*.94);
    }
    gl.bindBuffer(gl.ARRAY_BUFFER,trail.buffer);gl.bufferData(gl.ARRAY_BUFFER,new Float32Array(data),gl.DYNAMIC_DRAW);trail.count=data.length/8;
    gl.enable(gl.BLEND);gl.blendFunc(gl.SRC_ALPHA,gl.ONE_MINUS_SRC_ALPHA);gl.depthMask(false);drawMesh(trail,I(),[.94,.96,1],-1);gl.depthMask(true);gl.disable(gl.BLEND);
  }
  const texture=gl.createTexture();gl.bindTexture(gl.TEXTURE_2D,texture);gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,1,1,0,gl.RGBA,gl.UNSIGNED_BYTE,new Uint8Array([100,140,180,255]));
  gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.LINEAR);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_S,gl.CLAMP_TO_EDGE);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_T,gl.CLAMP_TO_EDGE);
  const image=new Image();image.onload=()=>{gl.bindTexture(gl.TEXTURE_2D,texture);gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,gl.RGBA,gl.UNSIGNED_BYTE,image);canvas.dataset.texture='loaded';requestDraw();};image.onerror=()=>{canvas.dataset.texture='failed';canvas.hidden=true;fallback.hidden=false;};image.src='assets/earth.jpg';
  gl.enable(gl.DEPTH_TEST);gl.clearColor(0,0,0,0);
  function drawMesh(mesh,model,color,textured=0){gl.bindBuffer(gl.ARRAY_BUFFER,mesh.buffer);for(const [name,size,offset] of [['position',3,0],['normal',3,12],['uv',2,24]]){gl.enableVertexAttribArray(attrs[name]);gl.vertexAttribPointer(attrs[name],size,gl.FLOAT,false,32,offset);}gl.uniformMatrix4fv(uniforms.model,false,new Float32Array(model));gl.uniform3fv(uniforms.color,color);gl.uniform1f(uniforms.textured,textured);gl.drawArrays(gl.TRIANGLES,0,mesh.count);}
  const journey=document.querySelector('.space-journey'),reduce=matchMedia('(prefers-reduced-motion: reduce)');
  let frame=0,visible=true,lost=false,lastTime=0,flightTime=2.1,smoothedScroll=0;
  function render(now){frame=0;const dt=lastTime?Math.min((now-lastTime)/1000,.05):0;lastTime=now;
    if(visible&&!document.hidden&&!reduce.matches)flightTime+=dt;
    const target=Math.max(0,Math.min(1,-journey.getBoundingClientRect().top/journey.offsetHeight));
    smoothedScroll+=(target-smoothedScroll)*(1-Math.exp(-dt*4));
    const p=reduce.matches?.5:((flightTime/18+smoothedScroll*.5)%1);
    const rect=canvas.getBoundingClientRect();const ratio=Math.min(devicePixelRatio,2);const width=Math.round(rect.width*ratio),height=Math.round(rect.height*ratio);if(canvas.width!==width||canvas.height!==height){canvas.width=width;canvas.height=height;}gl.viewport(0,0,width,height);gl.clear(gl.COLOR_BUFFER_BIT|gl.DEPTH_BUFFER_BIT);gl.uniform1f(uniforms.aspect,rect.width/rect.height);
    const mobile=innerWidth<601;
    const earth=mul(T(0,mobile?-3.2:-3.55,0),mul(R('z',-.16),mul(R('y',1.25+smoothedScroll*.1),S(mobile?3.45:3.55))));
    drawMesh(sphere,earth,[1,1,1],1);
    const t=-.12+1.24*p;const range=Math.min(6.8,rect.width/rect.height*3-.3);
    const x=-range+range*2*t,y=-3.2+3.05*Math.sin(Math.PI*t);
    const angle=Math.atan2(3.05*Math.PI*Math.cos(Math.PI*t),range*2);
    const scale=mobile?.58:.81;
    if(!reduce.matches)airflow(t,range,scale,flightTime);
    const plane=mul(T(x,y,4.5),mul(R('z',angle),mul(R('y',-.34),mul(R('x',.46-.08*Math.sin(Math.PI*t)),S(scale*1.12,scale,scale)))));
    const part=(geo,model,color)=>drawMesh(geo,mul(plane,model),color);
    for(const wing of wings)part(wing,T(0,-.055,0),wingColor);
    for(const tail of tails)part(tail,I(),wingColor);
    part(fuselage,I(),white);part(fin,I(),pink);
    for(const side of [-1,1])part(winglet,T(0,-.055,side*1.165),white);
    for(const z of [-.47,.47]){
      part(pylon,T(0,-.055,z),wingColor);
      const mount=mul(T(.035,-.22,z),S(.82,.72,.72));
      part(nacelle,mount,white);part(inlet,mount,[.63,.67,.71]);
      part(detailSphere,mul(mount,mul(T(.205,0,0),S(.005,.08,.08))),dark);
      part(fan,mount,[.32,.37,.4]);
      part(detailSphere,mul(mount,mul(T(.229,0,0),S(.032,.021,.021))),[.58,.61,.65]);
    }
    part(cockpit,I(),[.035,.075,.11]);part(windows,I(),dark);part(doors,I(),[.51,.55,.6]);part(doorInners,I(),white);
    canvas.dataset.progress=p.toFixed(3);canvas.dataset.plane=JSON.stringify({x,y,angle});canvas.dataset.renderer='webgl';
    if(visible&&!document.hidden&&!reduce.matches)requestDraw();
  }
  function requestDraw(){if(!frame&&!lost)frame=requestAnimationFrame(render);}
  addEventListener('scroll',requestDraw,{passive:true});addEventListener('resize',requestDraw);reduce.addEventListener('change',requestDraw);
  new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;lastTime=0;if(visible)requestDraw();else{cancelAnimationFrame(frame);frame=0;}},{threshold:0}).observe(journey);
  document.addEventListener('visibilitychange',()=>{lastTime=0;if(!document.hidden)requestDraw();else{cancelAnimationFrame(frame);frame=0;}});
  canvas.addEventListener('webglcontextlost',e=>{e.preventDefault();lost=true;cancelAnimationFrame(frame);frame=0;canvas.hidden=true;fallback.hidden=false;});requestDraw();
})();
