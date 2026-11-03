-- Login y permisos por maquina para el panel de vending.
-- Ejecutar en SQL Server dentro de la base usada por el proyecto.

IF OBJECT_ID('vendingm.usuarios', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.usuarios (
        id_usuario INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_usuarios PRIMARY KEY,
        nombre NVARCHAR(80) NOT NULL,
        apellido NVARCHAR(120) NOT NULL,
        email NVARCHAR(180) NOT NULL,
        password_hash NVARCHAR(255) NOT NULL,
        rol NVARCHAR(20) NOT NULL CONSTRAINT DF_usuarios_rol DEFAULT 'colaborador',
        activo BIT NOT NULL CONSTRAINT DF_usuarios_activo DEFAULT 1,
        ultimo_acceso DATETIME2(0) NULL,
        fecha_creacion DATETIME2(0) NOT NULL CONSTRAINT DF_usuarios_fecha_creacion DEFAULT SYSDATETIME(),
        fecha_actualizacion DATETIME2(0) NULL,
        CONSTRAINT UQ_usuarios_email UNIQUE (email),
        CONSTRAINT CK_usuarios_rol CHECK (rol IN ('admin', 'cliente', 'colaborador', 'medico', 'psicologo', 'nutriologo', 'tecnico_instalador', 'tecnico_soporte'))
    );
END;

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE name = 'CK_usuarios_rol'
      AND parent_object_id = OBJECT_ID('vendingm.usuarios')
      AND (definition NOT LIKE '%medico%' OR definition NOT LIKE '%psicologo%' OR definition NOT LIKE '%nutriologo%')
)
BEGIN
    ALTER TABLE vendingm.usuarios DROP CONSTRAINT CK_usuarios_rol;
    ALTER TABLE vendingm.usuarios ADD CONSTRAINT CK_usuarios_rol
        CHECK (rol IN ('admin', 'cliente', 'colaborador', 'medico', 'psicologo', 'nutriologo', 'tecnico_instalador', 'tecnico_soporte'));
END;

IF OBJECT_ID('vendingm.usuarios_maquinas', 'U') IS NULL
BEGIN
    CREATE TABLE vendingm.usuarios_maquinas (
        id_usuario INT NOT NULL,
        id_maquina INT NOT NULL,
        fecha_asignacion DATETIME2(0) NOT NULL CONSTRAINT DF_usuarios_maquinas_fecha DEFAULT SYSDATETIME(),
        CONSTRAINT PK_usuarios_maquinas PRIMARY KEY (id_usuario, id_maquina),
        CONSTRAINT FK_usuarios_maquinas_usuario FOREIGN KEY (id_usuario) REFERENCES vendingm.usuarios(id_usuario),
        CONSTRAINT FK_usuarios_maquinas_maquina FOREIGN KEY (id_maquina) REFERENCES vendingm.maquinas(id_maquina)
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'IX_usuarios_maquinas_maquina'
      AND object_id = OBJECT_ID('vendingm.usuarios_maquinas')
)
BEGIN
    CREATE INDEX IX_usuarios_maquinas_maquina ON vendingm.usuarios_maquinas (id_maquina, id_usuario);
END;

-- Primer acceso:
-- Despues de ejecutar este SQL, abre /login.php. Si no hay usuarios,
-- el sistema mostrara un formulario para crear el primer administrador.
